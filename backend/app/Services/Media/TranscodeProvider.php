<?php

namespace App\Services\Media;

use App\Models\Media;

/**
 * Hands a video off to Mux / Cloudflare Stream.
 *
 * We do not run ffmpeg on app servers. Losing a month to codec flags and a
 * queue that falls over on a 4 GB upload is not a differentiator.
 */
interface TranscodeProvider
{
    public function name(): string;

    /** Submit the asset; returns the provider's asset id. Readiness arrives by webhook. */
    public function submit(Media $media): string;
}
