<?php

namespace App\Jobs;

use App\ContentBlocks\BlockType;
use App\Models\Course;
use App\Models\CourseGeneration;
use App\Models\CourseNode;
use App\Services\Generation\BlueprintGenerator;
use App\Services\Generation\ContentWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fills teaching content for one topic of a generated course, then dispatches
 * itself for the next unfilled topic — a chain of short jobs. State lives in the
 * data: "next topic" is the next content-bearing node with no rich-text block,
 * so the chain is idempotent and resumable. When none remain, the generation is
 * marked complete. See {@see GenerateCourseJob} and docs/14-course-generation.md.
 */
class GenerateContentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout;

    public function __construct(public readonly string $generationId)
    {
        $this->timeout = (int) config('services.anthropic.generation_content_timeout', 180);
    }

    public function handle(BlueprintGenerator $blueprints, ContentWriter $writer): void
    {
        $generation = CourseGeneration::find($this->generationId);

        if ($generation === null || $generation->status !== CourseGeneration::PROCESSING) {
            return;
        }

        $course = $generation->course;
        if ($course === null) {
            return; // orchestrator hasn't built the structure; nothing to fill
        }

        $node = $this->nextUnfilledNode($course);

        // No topics left — the course is fully drafted.
        if ($node === null) {
            $this->cleanupPdf($generation);
            $generation->update(['status' => CourseGeneration::COMPLETED]);

            return;
        }

        $this->fill($blueprints, $writer, $generation, $node);

        // Continue with the next topic.
        self::dispatch($generation->id);
    }

    /**
     * Generate and write this node's content. A per-node failure writes a short
     * placeholder (so the chain advances and the author can fill it in) rather
     * than stalling or sinking the whole course.
     */
    private function fill(BlueprintGenerator $blueprints, ContentWriter $writer, CourseGeneration $generation, CourseNode $node): void
    {
        try {
            $result = $blueprints->contentFor($generation, $this->pathTitles($generation->name, $node));
            $writer->write($node, $node->schemaLevel, $result['text']);
            $generation->increment('input_tokens', $result['inputTokens']);
            $generation->increment('output_tokens', $result['outputTokens']);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Course generation: content pass failed for a node.', [
                'generation' => $generation->id,
                'node' => $node->id,
                'error' => $e->getMessage(),
            ]);
            $writer->write($node, $node->schemaLevel, 'Content could not be generated for this section. Please add it manually.');
        }
    }

    /** The next content-bearing node (in reading order) that has no rich-text block yet. */
    private function nextUnfilledNode(Course $course): ?CourseNode
    {
        return $course->nodes()
            ->with('schemaLevel')
            ->orderBy('path')
            ->get()
            ->first(fn (CourseNode $n) => $n->permitsBlockType(BlockType::RichText->value)
                && ! $n->blocks()->where('type', BlockType::RichText->value)->exists());
    }

    /**
     * Titles from the course root down to $node, for grounding the content call.
     *
     * @return list<string>
     */
    private function pathTitles(string $courseName, CourseNode $node): array
    {
        $titles = [];
        // Explicit finds (not $node->parent) to walk ancestors without a lazy load.
        for ($n = $node; $n !== null; $n = $n->parent_id !== null ? CourseNode::find($n->parent_id) : null) {
            array_unshift($titles, $n->title);
        }
        array_unshift($titles, $courseName);

        return $titles;
    }

    private function cleanupPdf(CourseGeneration $generation): void
    {
        if ($generation->pdf_path !== null) {
            Storage::disk(config('filesystems.default'))->delete($generation->pdf_path);
            $generation->update(['pdf_path' => null]);
        }
    }
}
