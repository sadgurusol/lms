<?php

namespace App\Services\Media;

use App\Models\Media;
use RuntimeException;

/**
 * The transcode provider's webhook lands here.
 *
 * Idempotent: providers retry, and a duplicate "asset.ready" must not reset a
 * playback id or clobber a later failure.
 */
final class MarkMediaReady
{
    public function ready(string $provider, string $assetId, string $playbackId, ?int $durationSeconds): Media
    {
        $media = $this->locate($provider, $assetId);

        if ($media->isReady()) {
            return $media;                       // replayed webhook
        }

        $media->update([
            'status' => Media::STATUS_READY,
            'playback_id' => $playbackId,
            'duration_s' => $durationSeconds,
        ]);

        return $media->refresh();
    }

    public function failed(string $provider, string $assetId, string $reason): Media
    {
        $media = $this->locate($provider, $assetId);

        $media->update([
            'status' => Media::STATUS_FAILED,
            'meta' => [...$media->meta, 'failure_reason' => $reason],
        ]);

        return $media->refresh();
    }

    private function locate(string $provider, string $assetId): Media
    {
        return Media::query()
            ->where('provider', $provider)
            ->where('provider_asset_id', $assetId)
            ->firstOr(fn () => throw new RuntimeException(
                "No media is registered for {$provider} asset [{$assetId}]."
            ));
    }
}
