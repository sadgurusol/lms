<?php

namespace App\Services\Generation;

use App\ContentBlocks\BlockType;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Services\Content\BlockEditor;
use App\Services\Tree\CourseTree;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Expands a Lesson node into child Step nodes from ai-platform-generated steps
 * (docs/14 §5.2b, node-per-step). Each platform Step becomes one Step node whose
 * blocks are produced by {@see StepMapper}.
 *
 * Resilient by node and by block: a step that can't be placed (schema/capacity)
 * or a block whose payload fails validation is skipped rather than sinking the
 * whole lesson — each write is committed on its own.
 */
final class LessonExpander
{
    public function __construct(
        private readonly CourseTree $tree,
        private readonly BlockEditor $blocks,
        private readonly StepMapper $mapper,
        private readonly ImageIngestor $images,
    ) {}

    /**
     * @param  array<int, mixed>  $steps  platform steps (native block shape; untrusted)
     * @return int number of Step nodes created
     */
    public function expand(CourseNode $lesson, SchemaLevel $stepLevel, array $steps): int
    {
        $course = $lesson->course;
        $created = 0;

        foreach ($steps as $i => $step) {
            if (! is_array($step)) {
                continue;
            }

            $title = trim((string) ($step['title'] ?? '')) ?: 'Step '.($i + 1);

            try {
                $node = $this->tree->appendNode($course, $stepLevel, $title, $lesson);
            } catch (RuntimeException) {
                continue; // over capacity / bad nesting — skip
            }

            $summary = trim((string) ($step['step_type'] ?? ''));
            if ($summary !== '') {
                $node->update(['summary' => ucfirst($summary)]);
            }

            foreach ($this->mapper->blocksFor($step) as $spec) {
                try {
                    if ($spec['type'] === BlockType::Image->value) {
                        $this->attachImage($node, $spec['payload']);
                    } else {
                        $this->blocks->appendAuthored($node, $spec['type'], $spec['payload']);
                    }
                } catch (Throwable $e) {
                    // A malformed/blocked payload must not sink the step — but log
                    // why, so a silently-dropped block (e.g. a level that doesn't
                    // permit the type) is diagnosable rather than invisible.
                    Log::warning('LessonExpander: skipped a block', [
                        'node' => $node->id,
                        'type' => $spec['type'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $created++;
        }

        return $created;
    }

    /**
     * Resolve an image spec ({src, alt, caption?}) into a real image block:
     * ingest the source into a Media record, then attach. If the picture can't
     * be fetched it's simply omitted — the step still stands on its text.
     *
     * @param  array<string, mixed>  $spec
     */
    private function attachImage(CourseNode $node, array $spec): void
    {
        $media = $this->images->ingest((string) ($spec['src'] ?? ''), $node->created_by);
        if ($media === null) {
            return;
        }

        $this->blocks->appendMedia($node, BlockType::Image->value, $media->id, array_filter([
            'alt' => trim((string) ($spec['alt'] ?? '')) ?: 'Illustration',
            'caption' => trim((string) ($spec['caption'] ?? '')) ?: null,
        ], fn ($v) => $v !== null));
    }
}
