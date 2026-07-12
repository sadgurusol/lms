<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a locally-transcoded video's bytes, with HTTP Range support so the
 * learner can seek. This exists only for the local/dev provider: in production
 * video is served by the transcode provider's CDN (Mux), so a Mux-backed asset
 * is never streamed from here.
 *
 * Authentication is required; fine-grained per-course entitlement is not
 * enforced here because this path is dev-only. The production CDN uses signed,
 * expiring URLs for that.
 */
class MediaStreamController extends Controller
{
    public function show(Media $media): BinaryFileResponse
    {
        abort_unless($media->kind === Media::KIND_VIDEO && $media->isReady(), 404);

        // Only assets we hold the bytes for (local provider). A Mux asset lives
        // on Mux's CDN and must not be requested here.
        abort_unless(in_array($media->provider, [null, 'local'], true), 404);

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        // A real filesystem path yields a BinaryFileResponse, which honours the
        // Range header (206 Partial Content) — essential for scrubbing a video.
        return response()->file($disk->path($media->path), [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
