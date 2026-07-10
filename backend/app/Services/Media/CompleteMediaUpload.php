<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CompleteMediaUpload
{
    public function __construct(private readonly TranscodeProvider $transcoder) {}

    /**
     * Called after the client's direct-to-bucket PUT succeeds.
     *
     * `$checksum` is supplied by the client. We verify the *size* against the
     * object store — that is authoritative — but we do not stream a 2 GB video
     * through PHP to recompute its SHA-256. The checksum is therefore a dedupe
     * key and a corruption hint, **not** a security control: never make an
     * authorization decision on it, and never treat a checksum match alone as
     * proof two users uploaded the same bytes.
     */
    public function handle(Media $media, string $checksum): Media
    {
        if ($media->status !== Media::STATUS_UPLOADING) {
            throw new RuntimeException("Media {$media->id} is {$media->status}, not awaiting an upload.");
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $checksum) !== 1) {
            throw new RuntimeException('Checksum must be a hex-encoded SHA-256 digest.');
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            throw new RuntimeException("No object was uploaded to {$media->path}.");
        }

        $actualSize = $disk->size($media->path);

        if ($media->size_bytes !== null && $actualSize !== $media->size_bytes) {
            $media->update(['status' => Media::STATUS_FAILED]);

            throw new RuntimeException(
                "Uploaded object is {$actualSize} bytes, but {$media->size_bytes} were declared."
            );
        }

        return DB::transaction(function () use ($media, $actualSize, $checksum) {
            if ($existing = $this->findDuplicate($media, $checksum)) {
                return $this->deduplicate($media, $existing, $actualSize, $checksum);
            }

            $media->update([
                'size_bytes' => $actualSize,
                'checksum_sha256' => strtolower($checksum),
                'status' => $media->needsTranscoding()
                    ? Media::STATUS_PROCESSING
                    : Media::STATUS_READY,
            ]);

            if ($media->needsTranscoding()) {
                $media->update([
                    'provider' => $this->transcoder->name(),
                    'provider_asset_id' => $this->transcoder->submit($media),
                ]);
            }

            return $media->refresh();
        });
    }

    /**
     * The same bytes are already uploaded and transcoded. Repoint this row at
     * the existing object and drop the duplicate we just received — otherwise
     * every re-uploaded diagram costs storage forever.
     *
     * `provider_asset_id` is deliberately not copied: it is unique per asset,
     * and two rows claiming the same Mux asset would make webhook routing
     * ambiguous.
     */
    private function deduplicate(Media $media, Media $existing, int $size, string $checksum): Media
    {
        $orphan = $media->path;

        $media->update([
            'status' => Media::STATUS_READY,
            'size_bytes' => $size,
            'checksum_sha256' => strtolower($checksum),
            'path' => $existing->path,
            'disk' => $existing->disk,
            'provider' => $existing->provider,
            'playback_id' => $existing->playback_id,
            'duration_s' => $existing->duration_s,
            'meta' => ['deduplicated_from' => $existing->id],
        ]);

        if ($orphan !== $existing->path) {
            Storage::disk($existing->disk)->delete($orphan);
        }

        return $media->refresh();
    }

    private function findDuplicate(Media $media, string $checksum): ?Media
    {
        return Media::query()
            ->where('checksum_sha256', strtolower($checksum))
            ->where('kind', $media->kind)
            ->whereKeyNot($media->id)
            ->ready()
            ->first();
    }
}
