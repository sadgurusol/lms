<?php

namespace App\Jobs;

use App\Ai\EmbeddingsClient;
use App\Models\CoursePublication;
use App\Tutor\ContentEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Embeds a publication's content for the AI tutor's retrieval, off the publish
 * request. Publishing a course should not wait on (or fail because of) an
 * embeddings API call.
 */
class EmbedPublicationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly string $publicationId) {}

    public function handle(EmbeddingsClient $embeddings, ContentEmbedder $embedder): void
    {
        // Config may have changed since dispatch; re-check rather than assume.
        if (! $embeddings->configured()) {
            return;
        }

        $publication = CoursePublication::find($this->publicationId);

        if ($publication !== null) {
            $embedder->embed($publication);
        }
    }
}
