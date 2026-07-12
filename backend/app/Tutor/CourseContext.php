<?php

namespace App\Tutor;

use App\Models\CoursePublication;

/**
 * Builds the grounding text a tutor answers from, read out of a course's
 * immutable snapshot: the course outline, the node the learner is reading, and
 * the sections retrieval judged most relevant to their question.
 *
 * The corpus is content blocks only. Assessments never appear here, so the tutor
 * cannot surface a quiz's answer key. See docs/12-ai-tutor.md.
 */
final class CourseContext
{
    /** Keep injected node text bounded, so a huge topic cannot blow the prompt. */
    private const FOCUS_CHAR_BUDGET = 8000;

    public function __construct(private readonly NodeFlattener $flatten) {}

    /**
     * @param  list<array{id: string, label: string, text: string}>  $retrieved
     * @return array{text: string, citations: list<array{id: string, label: string}>}
     */
    public function build(CoursePublication $publication, ?string $focusNodeId, array $retrieved = []): array
    {
        $tree = $publication->snapshot['tree'] ?? [];
        $title = $publication->snapshot['course']['title'] ?? 'this course';

        $outline = [];
        $this->outline($tree, $outline, 0);

        $sections = [
            "# Course: {$title}",
            "## Outline\n".implode("\n", $outline),
        ];

        /** @var list<array{id: string, label: string}> $citations */
        $citations = [];
        $seen = [];

        $focus = $focusNodeId === null ? null : $this->find($tree, $focusNodeId);
        if ($focus !== null) {
            $sections[] = "## The learner is currently reading\n".$this->focusText($focus, $citations, $seen);
        }

        // Retrieved sections most relevant to the question, minus anything already
        // shown as the focus.
        $relevant = [];
        foreach ($retrieved as $chunk) {
            if (isset($seen[$chunk['id']])) {
                continue;
            }
            $seen[$chunk['id']] = true;
            $citations[] = ['id' => $chunk['id'], 'label' => $chunk['label']];
            $relevant[] = "### {$chunk['label']}\n".mb_substr($chunk['text'], 0, self::FOCUS_CHAR_BUDGET);
        }
        if ($relevant !== []) {
            $sections[] = "## Relevant sections\n".implode("\n\n", $relevant);
        }

        return ['text' => implode("\n\n", $sections), 'citations' => $citations];
    }

    /**
     * @param  list<array<string, mixed>>  $branch
     * @param  list<string>  $out
     */
    private function outline(array $branch, array &$out, int $depth): void
    {
        foreach ($branch as $node) {
            $out[] = str_repeat('  ', $depth).'- '.$this->flatten->label($node);
            $this->outline($node['children'] ?? [], $out, $depth + 1);
        }
    }

    /**
     * The focused node's text plus its children's, up to a budget, collecting a
     * citation for each node whose text is included.
     *
     * @param  array<string, mixed>  $node
     * @param  list<array{id: string, label: string}>  $citations
     * @param  array<string, bool>  $seen
     */
    private function focusText(array $node, array &$citations, array &$seen, int $budget = self::FOCUS_CHAR_BUDGET): string
    {
        $label = $this->flatten->label($node);
        $body = $this->flatten->text($node);

        $chunk = "### {$label}\n".($body === '' ? '(no written content)' : $body);
        $id = (string) $node['id'];
        $seen[$id] = true;
        $citations[] = ['id' => $id, 'label' => $label];
        $remaining = $budget - mb_strlen($chunk);

        foreach ($node['children'] ?? [] as $child) {
            if ($remaining <= 0) {
                break;
            }
            $childText = $this->focusText($child, $citations, $seen, $remaining);
            $chunk .= "\n\n".$childText;
            $remaining -= mb_strlen($childText);
        }

        return $chunk;
    }

    /**
     * @param  list<array<string, mixed>>  $branch
     * @return array<string, mixed>|null
     */
    private function find(array $branch, string $nodeId): ?array
    {
        foreach ($branch as $node) {
            if (($node['id'] ?? null) === $nodeId) {
                return $node;
            }
            if ($found = $this->find($node['children'] ?? [], $nodeId)) {
                return $found;
            }
        }

        return null;
    }
}
