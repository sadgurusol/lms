<?php

namespace App\Console\Commands;

use App\Ai\EmbeddingsClient;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Tutor\ContentEmbedder;
use Illuminate\Console\Command;

/**
 * Build the AI tutor's retrieval embeddings for published courses.
 *
 * Publishing does this automatically when embeddings are configured; this
 * command backfills existing publications, or re-embeds after a model change.
 */
class TutorEmbedCommand extends Command
{
    protected $signature = 'tutor:embed {course? : A course id; omit to embed every published course}';

    protected $description = "Embed published course content for the AI tutor's retrieval";

    public function handle(EmbeddingsClient $embeddings, ContentEmbedder $embedder): int
    {
        if (! $embeddings->configured()) {
            $this->error('Embeddings are not configured. Set VOYAGE_API_KEY first.');

            return self::FAILURE;
        }

        $publications = CoursePublication::query()
            ->whereIn('id', Course::query()
                ->when($this->argument('course'), fn ($q, $id) => $q->whereKey($id))
                ->whereNotNull('latest_publication_id')
                ->pluck('latest_publication_id'))
            ->get();

        if ($publications->isEmpty()) {
            $this->info('No published courses to embed.');

            return self::SUCCESS;
        }

        foreach ($publications as $publication) {
            $count = $embedder->embed($publication);
            $this->line("  Embedded <comment>{$count}</comment> node(s) for publication {$publication->number} of course {$publication->course_id}");
        }

        $this->info(sprintf('Embedded %d publication(s).', $publications->count()));

        return self::SUCCESS;
    }
}
