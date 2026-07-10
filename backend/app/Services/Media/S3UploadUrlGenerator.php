<?php

namespace App\Services\Media;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class S3UploadUrlGenerator implements UploadUrlGenerator
{
    public function presign(string $disk, string $path, string $mime, DateTimeInterface $expiresAt): array
    {
        try {
            /** @var array{url: string, headers: array<string, string>} $presigned */
            $presigned = Storage::disk($disk)->temporaryUploadUrl($path, $expiresAt, ['ContentType' => $mime]);
        } catch (RuntimeException $e) {
            // Every FilesystemAdapter declares temporaryUploadUrl(); only the
            // S3 driver implements it. The others throw at call time.
            throw new RuntimeException("Disk [{$disk}] cannot issue presigned upload URLs.", previous: $e);
        }

        return $presigned;
    }
}
