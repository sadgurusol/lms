<?php

namespace App\Services\Media;

use DateTimeInterface;

/**
 * Dev stand-in for {@see S3UploadUrlGenerator}. There is no bucket to presign
 * against, so the "upload url" points at our own proxy endpoint, which writes
 * the bytes to the local disk. The two-step request → upload → complete flow is
 * then identical in dev and prod; only the destination differs.
 */
final class LocalUploadUrlGenerator implements UploadUrlGenerator
{
    public function presign(string $disk, string $path, string $mime, DateTimeInterface $expiresAt): array
    {
        return [
            'url' => route('studio.media.blob', ['path' => $path]),
            'headers' => ['Content-Type' => $mime],
        ];
    }
}
