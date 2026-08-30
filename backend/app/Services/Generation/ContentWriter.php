<?php

namespace App\Services\Generation;

use App\ContentBlocks\BlockType;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Services\Content\BlockEditor;
use RuntimeException;

/**
 * Writes AI-generated teaching text onto a course node: a rich-text block for the
 * prose (lightly-marked text — paragraphs, headings, bullets and inline bold /
 * italic / code — converted to Portable Text) plus a `diagram` block for each
 * inline fenced-SVG figure the model produced. Shared by {@see CourseBuilder}
 * and the per-topic content job.
 */
final class ContentWriter
{
    public function __construct(private readonly BlockEditor $blocks) {}

    /**
     * Append content to $node: a rich-text block for the prose, plus a `diagram`
     * block for each inline ```svg fenced figure the model produced (when the
     * level permits diagrams). The SVG is pulled out of the prose first.
     */
    public function write(CourseNode $node, SchemaLevel $level, string $content): void
    {
        if ($content === '' || ! $level->allows_content) {
            return;
        }

        [$prose, $diagrams] = $this->extractSvgDiagrams($content);

        if ($prose !== '' && in_array(BlockType::RichText->value, $level->allowed_block_types ?? [], true)) {
            try {
                $block = $this->blocks->append($node, BlockType::RichText->value);
                $this->blocks->updatePayload($block, [
                    'format' => 'portable_text',
                    'body' => $this->toPortableText($prose),
                ]);
            } catch (RuntimeException) {
                // A malformed block payload should not sink the node.
            }
        }

        if (in_array(BlockType::Diagram->value, $level->allowed_block_types ?? [], true)) {
            foreach ($diagrams as $diagram) {
                try {
                    $this->blocks->appendAuthored($node, BlockType::Diagram->value, $diagram);
                } catch (RuntimeException) {
                    // A malformed diagram should not sink the node.
                }
            }
        }
    }

    /**
     * Pull ```svg fenced blocks out of the content, returning the remaining prose
     * and a list of validated `diagram` payloads.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function extractSvgDiagrams(string $content): array
    {
        $diagrams = [];

        // ```svg  … ```  (optionally captioned by the line right after the fence)
        $prose = preg_replace_callback(
            '/```svg\s*\n(.*?)```/is',
            function (array $m) use (&$diagrams): string {
                $svg = $this->cleanSvg($m[1]);
                if ($svg !== null) {
                    $diagrams[] = ['format' => 'svg', 'svg' => $svg, 'alt' => 'Diagram'];
                }

                return ''; // remove the fence from the prose
            },
            $content,
        ) ?? $content;

        return [trim($prose), $diagrams];
    }

    /**
     * Keep only the `<svg>…</svg>` and strip anything scriptable, as defence in
     * depth — rendering is already script-inert (studio <img>, Flutter
     * SvgPicture), but we never store an obviously active payload. Public so the
     * ai-platform bridge ({@see StepMapper}) sanitizes diagrams the same way.
     */
    public function cleanSvg(string $raw): ?string
    {
        if (preg_match('/<svg\b.*?<\/svg>/is', $raw, $m) !== 1) {
            return null;
        }

        $svg = $m[0];
        $svg = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<foreignObject\b.*?</foreignObject\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;
        $svg = trim($svg);

        // The root <svg> must declare the SVG namespace, or it won't render when
        // loaded via an <img> data-URI / flutter_svg (only inline works). Inject
        // it when the model omitted it.
        if ($svg !== '' && ! preg_match('/<svg\b[^>]*\bxmlns\s*=/i', $svg)) {
            $svg = preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svg, 1) ?? $svg;
        }

        return $svg;
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
        $para = [];

        $flush = function () use (&$para, &$body) {
            if ($para !== []) {
                $body[] = $this->block('normal', implode(' ', $para));
                $para = [];
            }
        };

        // Line-based: AI content mixes headings, bullet and numbered lists with
        // single newlines, so a blank-line split alone leaves whole lists in one
        // paragraph. Wrapped prose lines still coalesce into one paragraph.
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
            $t = trim((string) $line);
            if ($t === '') {
                $flush();
            } elseif (preg_match('/^(#{1,3})\s+(.+)/', $t, $m) === 1) {
                $flush();
                $style = ['#' => 'h2', '##' => 'h3', '###' => 'h4'][$m[1]];
                $body[] = $this->block($style, trim($m[2]));
            } elseif (preg_match('/^\s*[-*]\s+(.+)/', $t, $m) === 1) {
                $flush();
                $body[] = $this->block('normal', trim($m[1]), listItem: 'bullet');
            } elseif (preg_match('/^\d+\.\s+(.+)/', $t) === 1) {
                // A numbered step: keep it as its own line (number preserved).
                $flush();
                $body[] = $this->block('normal', $t);
            } else {
                $para[] = $t;
            }
        }
        $flush();

        return $body;
    }

    /** @return array<string, mixed> */
    private function block(string $style, string $text, ?string $listItem = null): array
    {
        $block = [
            '_type' => 'block',
            'style' => $style,
            'markDefs' => [],
            'children' => $this->inlineSpans($text),
        ];

        if ($listItem !== null) {
            $block['listItem'] = $listItem;
        }

        return $block;
    }

    /**
     * Split inline markdown (**bold**, *italic* / _italic_, `code`) into spans
     * with marks — otherwise the syntax renders literally.
     *
     * @return list<array<string, mixed>>
     */
    private function inlineSpans(string $text): array
    {
        $spans = [];
        $offset = 0;
        $re = '/\*\*(.+?)\*\*|__(.+?)__|\*(.+?)\*|_(.+?)_|`(.+?)`/';

        while (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1];
            if ($start > $offset) {
                $spans[] = $this->span(substr($text, $offset, $start - $offset), []);
            }
            if (isset($m[1]) && $m[1][1] !== -1) {
                $spans[] = $this->span($m[1][0], ['strong']);
            } elseif (isset($m[2]) && $m[2][1] !== -1) {
                $spans[] = $this->span($m[2][0], ['strong']);
            } elseif (isset($m[3]) && $m[3][1] !== -1) {
                $spans[] = $this->span($m[3][0], ['em']);
            } elseif (isset($m[4]) && $m[4][1] !== -1) {
                $spans[] = $this->span($m[4][0], ['em']);
            } elseif (isset($m[5]) && $m[5][1] !== -1) {
                $spans[] = $this->span($m[5][0], ['code']);
            }
            $offset = $start + strlen($m[0][0]);
        }

        if ($offset < strlen($text)) {
            $spans[] = $this->span(substr($text, $offset), []);
        }

        return $spans === [] ? [$this->span($text, [])] : $spans;
    }

    /** @return array<string, mixed> */
    private function span(string $text, array $marks): array
    {
        return ['_type' => 'span', 'text' => $text, 'marks' => $marks];
    }

    /**
     * Reverse of toPortableText: reconstruct lightly-marked text from a portable
     * text body. Used to reformat content authored with the older converter.
     *
     * @param  array<int, array<string, mixed>>  $body
     */
    public function bodyToMarkdown(array $body): string
    {
        $lines = [];
        foreach ($body as $blk) {
            $text = '';
            foreach (($blk['children'] ?? []) as $span) {
                $t = (string) ($span['text'] ?? '');
                $marks = $span['marks'] ?? [];
                if (in_array('strong', $marks, true)) {
                    $t = "**{$t}**";
                } elseif (in_array('em', $marks, true)) {
                    $t = "*{$t}*";
                } elseif (in_array('code', $marks, true)) {
                    $t = "`{$t}`";
                }
                $text .= $t;
            }
            $style = $blk['style'] ?? 'normal';
            $lines[] = match (true) {
                $style === 'h2' => "# {$text}",
                $style === 'h3' => "## {$text}",
                $style === 'h4' => "### {$text}",
                ($blk['listItem'] ?? null) === 'bullet' => "- {$text}",
                default => $text,
            };
        }

        return implode("\n", $lines);
    }
}
