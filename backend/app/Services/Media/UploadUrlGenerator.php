<?php

namespace App\Services\Media;

use DateTimeInterface;

/**
 * Issues a presigned URL so the client PUTs bytes straight to the bucket.
 *
 * Never proxy uploads through php-fpm. A 2 GB lecture video through a PHP
 * worker will ruin your afternoon, and it holds a worker hostage for minutes.
 */
interface UploadUrlGenerator
{
    /** @return array{url: string, headers: array<string, string>} */
    public function presign(string $disk, string $path, string $mime, DateTimeInterface $expiresAt): array;
}
