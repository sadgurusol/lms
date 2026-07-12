<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\Media\MarkMediaReady;
use Illuminate\Console\Command;

/**
 * Stand in for the transcode provider's "asset.ready" webhook in local dev.
 *
 * The local transcoder leaves video in `processing` on purpose, so authors (and
 * tests) exercise the not-yet-ready paths. This flips one — or every — pending
 * local video to ready, the way Mux's webhook would in production.
 */
class MediaReadyCommand extends Command
{
    protected $signature = 'media:ready {media? : A media id; omit to ready every processing local video}
                            {--duration=180 : Duration in seconds to record}';

    protected $description = 'Mark local processing video as ready (dev transcode stand-in)';

    public function handle(MarkMediaReady $marker): int
    {
        $duration = (int) $this->option('duration');

        $query = Media::query()
            ->where('kind', Media::KIND_VIDEO)
            ->where('status', Media::STATUS_PROCESSING)
            ->where('provider', 'local')
            ->when($this->argument('media'), fn ($q, $id) => $q->whereKey($id));

        $pending = $query->get();

        if ($pending->isEmpty()) {
            $this->info('No processing local video to ready.');

            return self::SUCCESS;
        }

        foreach ($pending as $media) {
            // Playback for local assets streams the file, so the playback id is a
            // placeholder — it only needs to be present and stable.
            $marker->ready('local', (string) $media->provider_asset_id, 'local', $duration);
            $this->line("  Ready: <comment>{$media->id}</comment> ({$media->original_filename})");
        }

        $this->info(sprintf('Marked %d video(s) ready.', $pending->count()));

        return self::SUCCESS;
    }
}
