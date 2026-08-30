<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public, unauthenticated streaming of self-hosted (local) course video — the
 * portal twin of the bearer-gated Api\MediaStreamController. Access is limited to
 * video that lives in a publicly-accessible course; Mux assets are served from
 * Mux's own public CDN and are never requested here.
 */
class MediaController extends Controller
{
    public function stream(Media $media): BinaryFileResponse
    {
        abort_unless($media->kind === Media::KIND_VIDEO && $media->isReady(), 404);
        abort_unless(in_array($media->provider, [null, 'local'], true), 404);
        abort_unless($this->inPublicCourse($media), 404);

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        // A real filesystem path yields a BinaryFileResponse, which honours the
        // Range header (206 Partial Content) — essential for scrubbing.
        return response()->file($disk->path($media->path), [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** True when the media is used by a block in a publicly-accessible course. */
    private function inPublicCourse(Media $media): bool
    {
        return ContentBlock::query()
            ->where('media_id', $media->id)
            ->whereHas('courseNode.course', function ($q) {
                $q->whereNotNull('latest_publication_id')
                    ->where('workflow_state', '!=', Course::STATE_ARCHIVED)
                    ->whereIn('visibility', [Course::VIS_PUBLIC, Course::VIS_UNLISTED]);
            })
            ->exists();
    }
}
