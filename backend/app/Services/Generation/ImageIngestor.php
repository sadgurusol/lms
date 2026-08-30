<?php

namespace App\Services\Generation;

use App\Models\Media;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns an image the AI platform produced — a hosted URL or an inline data: URI
 * — into a ready {@see Media} record the LMS can reference from an `image`
 * block. Best-effort: anything unreadable, oversized, or not a raster image
 * returns null so the caller simply omits the picture rather than failing the
 * lesson. See docs/14-course-generation.md and {@see StepMapper}.
 */
final class ImageIngestor
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** Raster types we accept, mapped to a file extension. SVG is handled elsewhere. */
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function ingest(string $source, ?string $uploadedBy = null): ?Media
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        try {
            [$bytes, $mime] = str_starts_with($source, 'data:')
                ? $this->fromDataUri($source)
                : $this->fromUrl($source);
        } catch (ConnectionException $e) {
            Log::warning('Image ingest: could not fetch source.', ['error' => $e->getMessage()]);

            return null;
        }

        if ($bytes === null || ! isset(self::EXTENSIONS[$mime]) || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $checksum = hash('sha256', $bytes);

        // The same figure often recurs across steps — reuse an identical one.
        if ($existing = Media::query()
            ->where('kind', Media::KIND_IMAGE)
            ->where('checksum_sha256', $checksum)
            ->where('status', Media::STATUS_READY)
            ->first()) {
            return $existing;
        }

        $disk = config('filesystems.default');
        $path = sprintf('media/image/%s.%s', Str::uuid7(), self::EXTENSIONS[$mime]);
        Storage::disk($disk)->put($path, $bytes);

        return Media::create([
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => strlen($bytes),
            'checksum_sha256' => $checksum,
            'kind' => Media::KIND_IMAGE,
            'status' => Media::STATUS_READY,
            'uploaded_by' => $uploadedBy,
            'meta' => ['source' => 'ai_platform'],
        ]);
    }

    /**
     * @return array{0: ?string, 1: string} [bytes, mime]
     */
    private function fromUrl(string $url): array
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return [null, ''];
        }

        $response = Http::connectTimeout(15)->timeout(30)->get($url);
        if (! $response->successful()) {
            return [null, ''];
        }

        // Trust the served content-type over the URL extension.
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return [$response->body(), $mime];
    }

    /**
     * @return array{0: ?string, 1: string} [bytes, mime]
     */
    private function fromDataUri(string $uri): array
    {
        if (preg_match('#^data:([^;,]+)(;base64)?,(.*)$#s', $uri, $m) !== 1) {
            return [null, ''];
        }

        $mime = strtolower(trim($m[1]));
        $data = $m[3];
        $bytes = $m[2] === ';base64' ? base64_decode($data, true) : rawurldecode($data);

        return [$bytes === false ? null : $bytes, $mime];
    }
}
