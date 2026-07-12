<?php

namespace App\Tutor;

use App\Ai\EmbeddingsClient;
use App\Models\ContentEmbedding;
use App\Models\CoursePublication;

/**
 * Finds the course sections most relevant to a learner's question.
 *
 * Retrieval is scoped to a single publication's nodes (small N), so the
 * publication's embeddings are loaded and cosine-ranked in the app — no vector
 * index needed. See docs/12-ai-tutor.md.
 *
 * Degrades to nothing (empty result) when embeddings are not configured or a
 * publication has none, so the tutor still works on outline + focus alone.
 */
final class Retrieval
{
    public function __construct(private readonly EmbeddingsClient $embeddings) {}

    /**
     * @return list<array{id: string, label: string, text: string}>
     */
    public function relevantNodes(CoursePublication $publication, string $query, int $k = 4): array
    {
        if (! $this->embeddings->configured()) {
            return [];
        }

        $rows = ContentEmbedding::query()
            ->where('publication_id', $publication->id)
            ->get(['course_node_id', 'label', 'text', 'embedding']);

        if ($rows->isEmpty()) {
            return [];
        }

        $queryVector = $this->embeddings->embedQuery($query);

        return $rows
            ->map(fn (ContentEmbedding $row) => [
                'id' => $row->course_node_id,
                'label' => $row->label,
                'text' => $row->text,
                'score' => $this->cosine($queryVector, $row->embedding),
            ])
            ->sortByDesc('score')
            ->take($k)
            ->map(fn (array $r) => ['id' => $r['id'], 'label' => $r['label'], 'text' => $r['text']])
            ->values()
            ->all();
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $length = min(count($a), count($b));
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
