<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'disk' => 'local',
            'path' => 'media/image/'.Str::uuid7().'.png',
            'original_filename' => 'diagram.png',
            'mime' => 'image/png',
            'size_bytes' => 91234,
            'checksum_sha256' => hash('sha256', Str::random(16)),
            'kind' => Media::KIND_IMAGE,
            'status' => Media::STATUS_READY,
        ];
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'path' => 'media/video/'.Str::uuid7().'.mp4',
            'original_filename' => 'lecture.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 48210233,
            'kind' => Media::KIND_VIDEO,
            'provider' => 'local',
            'provider_asset_id' => 'local_'.Str::random(24),
            'playback_id' => Str::random(20),
            'duration_s' => 412,
        ]);
    }

    public function document(): static
    {
        return $this->state(fn () => [
            'path' => 'media/document/'.Str::uuid7().'.pdf',
            'original_filename' => 'worksheet.pdf',
            'mime' => 'application/pdf',
            'kind' => Media::KIND_DOCUMENT,
        ]);
    }

    public function uploading(): static
    {
        return $this->state(fn () => [
            'status' => Media::STATUS_UPLOADING,
            'checksum_sha256' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => Media::STATUS_PROCESSING,
            'playback_id' => null,
            'duration_s' => null,
        ]);
    }
}
