<?php

namespace App\Services\Publishing;

use App\ContentBlocks\BlockPayloadValidator;
use App\ContentBlocks\BlockType;
use App\ContentBlocks\InvalidBlockPayload;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\ReviewComment;
use App\Models\SchemaLevel;
use Illuminate\Support\Collection;

/**
 * A pure function over the draft tree.
 *
 * Errors block publication; warnings do not. The same instance backs both
 * `GET /courses/{c}/validate` (the editor's live readiness panel) and the
 * publish gate — never two implementations of the same rules.
 *
 * Cardinality (I12) is checked *here*, not in a trigger. Enforcing
 * "a Part must contain at least one Chapter" on every save makes it impossible
 * to create an empty Part and then fill it, which is how authoring works.
 */
final class CourseValidator
{
    public function __construct(private readonly BlockPayloadValidator $payloads) {}

    /** @return list<Finding> */
    public function validate(Course $course): array
    {
        $levels = $course->schemaVersion->levels()->get()->keyBy('id');
        $nodes = $course->nodes()->with('blocks.media')->orderBy('path')->get();

        /** @var array<string, list<SchemaLevel>> $childLevels */
        $childLevels = [];
        foreach ($levels as $level) {
            $childLevels[$level->parent_level_id ?? ''][] = $level;
        }

        $findings = [
            ...$this->checkRootCardinality($course, $levels, $nodes),
            ...$this->checkNodes($course, $levels, $childLevels, $nodes),
            ...$this->checkUnresolvedComments($course),
        ];

        // Errors first: the readiness panel shows what blocks publication above
        // what merely deserves attention.
        usort($findings, fn (Finding $a, Finding $b) => $b->isError() <=> $a->isError());

        return $findings;
    }

    /** @return list<Finding> */
    public function errors(Course $course): array
    {
        return array_values(array_filter($this->validate($course), fn (Finding $f) => $f->isError()));
    }

    /**
     * @param  Collection<array-key, SchemaLevel>  $levels
     * @param  Collection<int, CourseNode>  $nodes
     * @return list<Finding>
     */
    private function checkRootCardinality(Course $course, Collection $levels, Collection $nodes): array
    {
        $findings = [];
        $roots = $nodes->whereNull('parent_id');

        foreach ($levels->whereNull('parent_level_id') as $level) {
            $count = $roots->where('schema_level_id', $level->id)->count();

            if ($count < $level->min_occurrences) {
                $findings[] = Finding::error(
                    'E_ORPHAN_LEVEL',
                    "This course must contain at least {$level->min_occurrences} {$level->plural_name}; it has {$count}.",
                    'course', $course->id,
                );
            }
        }

        return $findings;
    }

    /**
     * @param  Collection<array-key, SchemaLevel>  $levels
     * @param  array<string, list<SchemaLevel>>  $childLevels
     * @param  Collection<int, CourseNode>  $nodes
     * @return list<Finding>
     */
    private function checkNodes(Course $course, Collection $levels, array $childLevels, Collection $nodes): array
    {
        $findings = [];
        $byParent = $nodes->groupBy('parent_id');

        foreach ($nodes as $node) {
            $level = $levels[$node->schema_level_id];
            $children = $byParent[$node->id] ?? collect();
            $permittedChildLevels = $childLevels[$level->id] ?? [];

            foreach ($permittedChildLevels as $childLevel) {
                $count = $children->where('schema_level_id', $childLevel->id)->count();

                if ($count < $childLevel->min_occurrences) {
                    $findings[] = Finding::error(
                        'E_MIN_OCCURRENCES',
                        "{$level->name} \"{$node->title}\" must contain at least {$childLevel->min_occurrences} "
                            ."{$childLevel->plural_name}; it has {$count}.",
                        'node', $node->id,
                    );
                }
            }

            // A content-bearing node with no children and no blocks is a dead end
            // the learner will land on and find empty.
            if ($level->allows_content && $permittedChildLevels === [] && $node->blocks->isEmpty()) {
                $findings[] = Finding::error(
                    'E_EMPTY_LEAF',
                    "{$level->name} \"{$node->title}\" has no content.",
                    'node', $node->id,
                );
            }

            $findings = [...$findings, ...$this->checkBlocks($node, $level)];
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function checkBlocks(CourseNode $node, SchemaLevel $level): array
    {
        $findings = [];

        if ($node->blocks->count() > 30) {
            $findings[] = Finding::warning(
                'W_LONG_NODE',
                "{$level->name} \"{$node->title}\" has {$node->blocks->count()} blocks; consider splitting it.",
                'node', $node->id,
            );
        }

        foreach ($node->blocks as $block) {
            $type = $block->blockType();

            // A raw query-builder insert can put a schema-invalid payload in the
            // table (the saving hook is application-level). Publishing is the
            // gate that catches it before a learner ever sees it.
            try {
                $this->payloads->validate($type, $block->payload ?? [], $block->media_id);
            } catch (InvalidBlockPayload $e) {
                $findings[] = Finding::error('E_BLOCK_SCHEMA', $e->getMessage(), 'block', $block->id);

                continue;
            }

            $findings = [...$findings, ...$this->checkBlockMedia($block, $type)];
            $findings = [...$findings, ...$this->checkAccessibility($block, $type)];
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function checkBlockMedia(ContentBlock $block, BlockType $type): array
    {
        if (! $type->requiresReadyMedia()) {
            return [];
        }

        // Eager-loaded in validate(): resolving this per block would be one
        // query per video on a course that may have hundreds.
        $media = $block->media;

        if ($media === null || ! $media->isReady()) {
            return [Finding::error(
                'E_MEDIA_NOT_READY',
                sprintf(
                    'A %s block references media that is %s.',
                    $type->value,
                    $media === null ? 'missing' : $media->status,
                ),
                'block', $block->id,
            )];
        }

        return [];
    }

    /**
     * Accessibility findings are warnings, not errors: they must be visible on
     * every publish, and they must never be the reason a school's content is
     * blocked the night before term starts.
     *
     * @return list<Finding>
     */
    private function checkAccessibility(ContentBlock $block, BlockType $type): array
    {
        $findings = [];

        if ($type === BlockType::Image && trim((string) ($block->payload['alt'] ?? '')) === '') {
            $findings[] = Finding::warning(
                'W_MISSING_ALT',
                'An image has no alt text; screen readers will skip it.',
                'block', $block->id,
            );
        }

        if ($type === BlockType::Video && ($block->payload['captions'] ?? []) === []) {
            $findings[] = Finding::warning(
                'W_NO_CAPTIONS',
                'A video has no caption track.',
                'block', $block->id,
            );
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function checkUnresolvedComments(Course $course): array
    {
        $open = ReviewComment::query()
            ->whereNull('resolved_at')
            ->whereIn('review_request_id', $course->reviewRequests()->select('id'))
            ->count();

        return $open === 0 ? [] : [Finding::warning(
            'W_UNRESOLVED_COMMENTS',
            "{$open} review comment(s) remain unresolved.",
            'course', $course->id,
        )];
    }
}
