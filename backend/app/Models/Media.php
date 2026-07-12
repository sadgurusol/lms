<?php

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $disk
 * @property string $path
 * @property string|null $original_filename
 * @property string $mime
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property string $kind
 * @property string|null $provider
 * @property string|null $provider_asset_id
 * @property string|null $playback_id
 * @property int|null $duration_s
 * @property string $status
 * @property array<string, mixed> $meta
 * @property string|null $uploaded_by
 */
#[Fillable([
    'disk', 'path', 'original_filename', 'mime', 'size_bytes', 'checksum_sha256',
    'kind', 'provider', 'provider_asset_id', 'playback_id', 'duration_s',
    'status', 'meta', 'uploaded_by',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'media';

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    public const KIND_DOCUMENT = 'document';

    public const KIND_AUDIO = 'audio';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * A servable URL for the object. Video is played through a provider, so it
     * stays null for video.
     *
     * A local disk is served through the app (see MediaFileController) rather
     * than the `/storage` symlink: the symlink bypasses middleware under
     * `artisan serve`, so it carries no CORS headers and the cross-origin
     * learner web app cannot load it. Object storage (S3) returns its own bucket
     * URL, configured with CORS at the bucket.
     */
    public function url(): ?string
    {
        if ($this->kind === self::KIND_VIDEO) {
            return null;
        }

        if (config("filesystems.disks.{$this->disk}.driver") === 'local') {
            return route('api.media.file', ['media' => $this->id]);
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /** Only video needs a transcode round-trip; everything else is ready on upload. */
    public function needsTranscoding(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    /** @param Builder<Media> $query */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', self::STATUS_READY);
    }
}
