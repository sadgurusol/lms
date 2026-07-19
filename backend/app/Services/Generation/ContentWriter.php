<?php

namespace App\Services\Generation;

use App\ContentBlocks\BlockType;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Services\Content\BlockEditor;
use RuntimeException;

/**
 * Writes AI-generated teaching text onto a course node as a rich-text block,
 * converting lightly-marked text (paragraphs, #/##/### headings, - bullets) to
 * Portable Text. Shared by {@see CourseBuilder} and the per-topic content job.
 */
final class ContentWriter
{
    public function __construct(private readonly BlockEditor $blocks) {}

    /** Append a rich-text block with $content to $node, if the level permits it. */
    public function write(CourseNode $node, SchemaLevel $level, string $content): void
    {
        if ($content === '' || ! $level->allows_content
            || ! in_array(BlockType::RichText->value, $level->allowed_block_types ?? [], true)) {
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
    public function toPortableText(string $text): array
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
}
