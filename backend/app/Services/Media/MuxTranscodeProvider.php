<?php

namespace App\Services\Media;

use App\Http\Controllers\Api\MediaWebhookController;
use App\Models\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hands the uploaded object to Mux, which transcodes it and calls our webhook
 * when it is ready (see {@see MediaWebhookController}).
 *
 * Mux ingests from a URL, so we hand it a short-lived signed link to the object
 * we just received rather than re-uploading the bytes.
 */
final class MuxTranscodeProvider implements TranscodeProvider
{
    public function name(): string
    {
        return 'mux';
    }

    public function submit(Media $media): string
    {
        $response = Http::withBasicAuth(
            (string) config('services.mux.token_id'),
            (string) config('services.mux.token_secret'),
        )
            ->acceptJson()
            ->post('https://api.mux.com/video/v1/assets', [
                'input' => [['url' => $this->ingestUrl($media)]],
                'playback_policy' => ['public'],
                'passthrough' => $media->id,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Mux rejected the asset for media {$media->id}: {$response->status()}.");
        }

        $assetId = $response->json('data.id');

        if (! is_string($assetId) || $assetId === '') {
            throw new RuntimeException("Mux returned no asset id for media {$media->id}.");
        }

        return $assetId;
    }

    /** A signed, short-lived URL Mux can pull the source from. */
    private function ingestUrl(Media $media): string
    {
        $disk = Storage::disk($media->disk);

        try {
            return $disk->temporaryUrl($media->path, now()->addMinutes(30));
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                "Disk [{$media->disk}] cannot issue a temporary read URL for Mux ingest.",
                previous: $e,
            );
        }
    }
}
