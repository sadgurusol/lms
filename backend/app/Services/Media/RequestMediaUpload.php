<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

final class RequestMediaUpload
{
    /** Mime prefixes we accept, mapped to the media kind they become. */
    private const KINDS = [
        'image/' => Media::KIND_IMAGE,
        'video/' => Media::KIND_VIDEO,
        'audio/' => Media::KIND_AUDIO,
        'application/pdf' => Media::KIND_DOCUMENT,
        'application/vnd.openxmlformats' => Media::KIND_DOCUMENT,
        'text/plain' => Media::KIND_DOCUMENT,
    ];

    private const MAX_BYTES = [
        Media::KIND_IMAGE => 20 * 1024 * 1024,
        Media::KIND_VIDEO => 5 * 1024 * 1024 * 1024,
        Media::KIND_AUDIO => 500 * 1024 * 1024,
        Media::KIND_DOCUMENT => 100 * 1024 * 1024,
    ];

    public function __construct(private readonly UploadUrlGenerator $urls) {}

    /** @return array{media: Media, upload_url: string, headers: array<string, string>} */
    public function handle(User $actor, string $filename, string $mime, int $sizeBytes): array
    {
        $kind = $this->kindFor($mime);

        if ($sizeBytes > self::MAX_BYTES[$kind]) {
            throw new RuntimeException(
                sprintf('%s uploads are limited to %d MB.', ucfirst($kind), self::MAX_BYTES[$kind] / 1048576)
            );
        }

        $disk = config('filesystems.default');
        $path = sprintf('media/%s/%s.%s', $kind, Str::uuid7(), $this->extension($filename));

        $media = Media::create([
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $filename,
            'mime' => $mime,
            'size_bytes' => $sizeBytes,      // claimed; verified on complete
            'kind' => $kind,
            'status' => Media::STATUS_UPLOADING,
            'uploaded_by' => $actor->id,
        ]);

        $presigned = $this->urls->presign($disk, $path, $mime, now()->addMinutes(30));

        return [
            'media' => $media,
            'upload_url' => $presigned['url'],
            'headers' => $presigned['headers'],
        ];
    }

    private function kindFor(string $mime): string
    {
        foreach (self::KINDS as $prefix => $kind) {
            if (str_starts_with($mime, $prefix)) {
                return $kind;
            }
        }

        throw new RuntimeException("Unsupported media type [{$mime}].");
    }

    private function extension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // The extension is decoration on a UUID path; never trust it as a type.
        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
    }
}
