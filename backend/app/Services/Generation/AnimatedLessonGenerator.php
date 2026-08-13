<?php

namespace App\Services\Generation;

use App\Ai\AiPlatformClient;
use App\ContentBlocks\BlockType;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use RuntimeException;

/**
 * Generates an interactive, animated lesson for a Lesson node via the ai-platform
 * and materialises it as child Step nodes (docs/14 WS2). The platform owns the
 * step breakdown, animated reveals and simulations; this service maps that output
 * into the LMS content tree.
 *
 * Requires the platform to be enabled. The plain-text course pipeline
 * ({@see BlueprintGenerator}/{@see ContentWriter}, direct Anthropic) remains the
 * fallback for non-animated generation.
 */
final class AnimatedLessonGenerator
{
    public function __construct(
        private readonly AiPlatformClient $platform,
        private readonly LessonExpander $expander,
    ) {}

    /**
     * @param  array<string, mixed>  $context  topic|grade_level|subject|objectives|content|instructions
     * @return int  number of Step nodes created
     */
    public function generate(CourseNode $lesson, array $context = []): int
    {
        if (! $this->platform->isEnabled()) {
            throw new RuntimeException('AI Platform is not enabled — cannot generate an animated lesson.');
        }

        $stepLevel = $this->resolveStepLevel($lesson);
        if ($stepLevel === null) {
            throw new RuntimeException(
                "This lesson's schema has no child level that accepts animated lessons "
                .'(a content level allowing the "animated_reveal" block).'
            );
        }

        $context = $this->context($lesson, $context);
        $steps = $this->platform->generateLesson($context);

        if ($steps === []) {
            throw new RuntimeException('The AI Platform returned no steps for this lesson.');
        }

        return $this->expander->expand($lesson, $stepLevel, $steps);
    }

    /** The child level of the lesson that carries animated-reveal content. */
    private function resolveStepLevel(CourseNode $lesson): ?SchemaLevel
    {
        return SchemaLevel::query()
            ->where('parent_level_id', $lesson->schema_level_id)
            ->get()
            ->first(fn (SchemaLevel $l) => $l->allows_content
                && in_array(BlockType::AnimatedReveal->value, $l->allowed_block_types ?? [], true));
    }

    /**
     * Build the platform request context, defaulting from the node's place in the
     * tree (topic ← lesson title, chapter ← parent title).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function context(CourseNode $lesson, array $overrides): array
    {
        $course = $lesson->course;

        return array_merge([
            'topic' => $lesson->title,
            'chapter' => $lesson->parent?->title,
            'subject' => $course?->subject,
            'objectives' => [],
            'content' => $lesson->summary,
        ], array_filter($overrides, fn ($v) => $v !== null && $v !== ''));
    }
}
