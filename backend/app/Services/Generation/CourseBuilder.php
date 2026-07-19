<?php

namespace App\Services\Generation;

use App\ContentBlocks\BlockType;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Models\User;
use App\Services\Content\BlockEditor;
use App\Services\Courses\CreateCourse;
use App\Services\Tree\CourseTree;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turns a validated blueprint (an AI-produced outline) into a draft course, using
 * the same authoring services a human uses. Deterministic and side-effect
 * contained: given the same blueprint it builds the same course, so it is tested
 * without any AI in the loop. See docs/14-course-generation.md.
 *
 * Resilient by node: a spec that violates the schema (unknown level, over
 * capacity) is skipped rather than failing the whole course — each node is its
 * own committed write. If nothing builds, that's a failure worth surfacing.
 */
final class CourseBuilder
{
    public function __construct(
        private readonly CreateCourse $createCourse,
        private readonly CourseTree $tree,
        private readonly BlockEditor $blocks,
    ) {}

    private int $created = 0;

    /**
     * @param  array<string, mixed>  $blueprint  ['nodes' => [ {level,title,summary?,content?,children?} ]]
     */
    public function build(array $blueprint, SchemaVersion $version, string $name, User $actor): Course
    {
        $levelsByName = $version->levels()->get()->keyBy(fn (SchemaLevel $l) => mb_strtolower($l->name));

        $course = $this->createCourse->handle(['title' => $name, 'language' => 'en'], $version, $actor);

        $this->created = 0;
        $this->buildNodes($course, $this->nodesFrom($blueprint), null, $levelsByName);

        if ($this->created === 0) {
            throw new RuntimeException('The generated outline did not match the schema; no sections were created.');
        }

        return $course->fresh();
    }

    /**
     * @param  list<mixed>  $nodes
     * @param  Collection<string, SchemaLevel>  $levels
     */
    private function buildNodes(Course $course, array $nodes, ?CourseNode $parent, Collection $levels): void
    {
        foreach ($nodes as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $level = $levels->get(mb_strtolower(trim((string) ($spec['level'] ?? ''))));
            if ($level === null) {
                continue; // the AI named a level this schema doesn't have
            }

            $title = trim((string) ($spec['title'] ?? '')) ?: 'Untitled';

            try {
                $node = $this->tree->appendNode($course, $level, $title, $parent);
            } catch (RuntimeException) {
                continue; // over capacity / bad nesting — skip this node and its subtree
            }

            $this->created++;

            $summary = trim((string) ($spec['summary'] ?? ''));
            if ($summary !== '') {
                $node->update(['summary' => $summary]);
            }

            $this->addContent($node, $level, trim((string) ($spec['content'] ?? '')));

            $children = $spec['children'] ?? [];
            if (is_array($children)) {
                $this->buildNodes($course, $children, $node, $levels);
            }
        }
    }

    private function addContent(CourseNode $node, SchemaLevel $level, string $content): void
    {
        if ($content === '' || ! $level->allows_content
            || ! in_array(BlockType::RichText->value, $level->allowed_block_types, true)) {
            return;
        }

        try {
            $block = $this->blocks->append($node, BlockType::RichText->value);
            $this->blocks->updatePayload($block, [
                'format' => 'portable_text',
                'body' => $this->toPortableText($content),
            ]);
        } catch (RuntimeException) {
            // A malformed block payload should not sink the node.
        }
    }

    /**
     * Convert lightly-marked text (paragraphs, #/##/### headings, - bullets) into
     * Portable Text blocks.
     *
     * @return list<array<string, mixed>>
     */
    private function toPortableText(string $text): array
    {
        $body = [];

        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)/s', $chunk, $m) === 1) {
                $style = ['#' => 'h2', '##' => 'h3', '###' => 'h4'][$m[1]];
                $body[] = $this->block($style, trim($m[2]));
            } elseif (preg_match('/^\s*[-*]\s+/', $chunk) === 1) {
                foreach (explode("\n", $chunk) as $line) {
                    $item = trim(preg_replace('/^\s*[-*]\s+/', '', $line) ?? '');
                    if ($item !== '') {
                        $body[] = $this->block('normal', $item, listItem: 'bullet');
                    }
                }
            } else {
                $body[] = $this->block('normal', $chunk);
            }
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function block(string $style, string $text, ?string $listItem = null): array
    {
        $block = [
            '_type' => 'block',
            'style' => $style,
            'markDefs' => [],
            'children' => [['_type' => 'span', 'text' => $text, 'marks' => []]],
        ];

        if ($listItem !== null) {
            $block['listItem'] = $listItem;
        }

        return $block;
    }

    /** @return list<array<string, mixed>> */
    private function nodesFrom(array $blueprint): array
    {
        $nodes = $blueprint['nodes'] ?? [];

        return is_array($nodes) ? array_values($nodes) : [];
    }
}
