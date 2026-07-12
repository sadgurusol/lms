<?php

namespace App\Services\Media;

use App\Models\Media;

/**
 * Resolves how a ready video is played back, denormalised into the snapshot so
 * the player needs no second round trip.
 *
 * Two worlds: a real provider (Mux) serves adaptive HLS from a playback id and
 * a poster from its image service; the local dev provider has no CDN, so it
 * streams the original file straight from our own endpoint (progressive mp4).
 */
final class MediaPlayback
{
    /**
     * Baked playback fields for a ready video, or null if it is not ready.
     *
     * @return array{src: string, src_type: string, poster: string|null, duration_s: int|null}|null
     */
    public function video(Media $media): ?array
    {
        if ($media->kind !== Media::KIND_VIDEO || ! $media->isReady()) {
            return null;
        }

        if ($media->provider === 'mux' && $media->playback_id !== null) {
            $id = $media->playback_id;

            return [
                'src' => "https://stream.mux.com/{$id}.m3u8",
                'src_type' => 'hls',
                'poster' => "https://image.mux.com/{$id}/thumbnail.webp?width=1280",
                'duration_s' => $media->duration_s,
            ];
        }

        // Local/dev: progressive download from our own range-capable endpoint.
        return [
            'src' => route('api.media.stream', ['media' => $media->id]),
            'src_type' => 'mp4',
            'poster' => null,
            'duration_s' => $media->duration_s,
        ];
    }
}
