<?php

namespace App\Tutor;

use App\Ai\EmbeddingsClient;
use App\Models\ContentEmbedding;
use App\Models\CoursePublication;
use Illuminate\Support\Facades\DB;

/**
 * Embeds a publication's content nodes so the tutor can retrieve them.
 *
 * Run at publish (guarded by config) and by `tutor:embed`. Idempotent: it
 * replaces the publication's embeddings wholesale, so a re-run is safe.
 */
final class ContentEmbedder
{
    public function __construct(
        private readonly EmbeddingsClient $embeddings,
        private readonly NodeFlattener $flatten,
    ) {}

    /** Returns the number of nodes embedded. */
    public function embed(CoursePublication $publication): int
    {
        $chunks = [];
        $this->collect($publication->snapshot['tree'] ?? [], $chunks);

        if ($chunks === []) {
            return 0;
        }

        $vectors = $this->embeddings->embedDocuments(array_column($chunks, 'text'));
        $model = (string) config('services.voyage.model');

        DB::transaction(function () use ($publication, $chunks, $vectors, $model) {
            ContentEmbedding::where('publication_id', $publication->id)->delete();

            foreach ($chunks as $i => $chunk) {
                ContentEmbedding::create([
                    'publication_id' => $publication->id,
                    'course_node_id' => $chunk['id'],
                    'chunk_index' => 0,
                    'label' => $chunk['label'],
                    'text' => $chunk['text'],
                    'embedding' => $vectors[$i],
                    'model' => $model,
                ]);
            }
        });

        return count($chunks);
    }

    /**
     * Every content-bearing node, flattened. A node with no written text (a pure
     * grouping level, or one that only holds a video) is skipped.
     *
     * @param  list<array<string, mixed>>  $branch
     * @param  list<array{id: string, label: string, text: string}>  $chunks
     */
    private function collect(array $branch, array &$chunks): void
    {
        foreach ($branch as $node) {
            $text = $this->flatten->text($node);

            if (trim($text) !== '') {
                $label = $this->flatten->label($node);
                $chunks[] = ['id' => (string) $node['id'], 'label' => $label, 'text' => "{$label}\n{$text}"];
            }

            $this->collect($node['children'] ?? [], $chunks);
        }
    }
}
