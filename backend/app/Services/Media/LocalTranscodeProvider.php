<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Support\Str;

/**
 * Local/dev stand-in: pretends to submit, and the asset stays in `processing`
 * until something calls MarkMediaReady — which `php artisan media:ready` does.
 *
 * Deliberately does *not* mark ready immediately: a local environment where
 * video is instantly playable hides every bug in the not-yet-ready code paths.
 */
final class LocalTranscodeProvider implements TranscodeProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function submit(Media $media): string
    {
        return 'local_'.Str::random(24);
    }
}
