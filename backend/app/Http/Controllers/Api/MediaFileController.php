<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves image/document/audio bytes for a media asset held on a local disk.
 *
 * Why not the `/storage` symlink? Under `php artisan serve` a file that exists
 * in public/ is served directly by the PHP dev server, bypassing the middleware
 * stack — so no CORS headers, and the cross-origin learner web app (CanvasKit
 * fetches images over XHR) is blocked. Routing the bytes through the app puts
 * them under the CORS policy. In production, object storage (S3) serves its own
 * URL with bucket CORS, so this route is only used for local/public disks.
 *
 * Public by design: content images were already public via `/storage`, so this
 * keeps that contract while adding the CORS headers the app needs.
 */
class MediaFileController extends Controller
{
    public function show(Media $media): BinaryFileResponse
    {
        // Video streams through its own range-capable endpoint.
        abort_if($media->kind === Media::KIND_VIDEO, 404);
        abort_unless($media->isReady(), 404);

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        return response()->file($disk->path($media->path), [
            'Content-Type' => $media->mime,
            // Immutable: the path is a content-addressed UUID, so it never changes.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
