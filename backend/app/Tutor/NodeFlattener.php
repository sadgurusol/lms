<?php

namespace App\Tutor;

/**
 * Flattens a snapshot node's text-bearing blocks to plain text, shared by the
 * grounding builder and the embedder so both index the same words.
 *
 * Text-bearing content only — video/embed carry none, and assessments are not
 * part of the snapshot tree at all, so the tutor can never see an answer key.
 */
final class NodeFlattener
{
    /**
     * A node's own blocks (not its children), flattened.
     *
     * @param  array<string, mixed>  $node
     */
    public function text(array $node): string
    {
        $parts = [];

        foreach ($node['blocks'] ?? [] as $block) {
            $payload = $block['payload'] ?? [];

            $text = match ($block['type'] ?? '') {
                'rich_text' => $this->portableText($payload['body'] ?? null),
                'callout' => trim(($payload['title'] ?? '').': '.$this->portableText($payload['body'] ?? null)),
                'image' => isset($payload['caption']) ? "[image: {$payload['caption']}]" : '',
                'attachment' => isset($payload['filename']) ? "[file: {$payload['filename']}]" : '',
                default => '',
            };

            if (trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        return implode("\n\n", $parts);
    }

    /** Flatten a Portable Text document to plain paragraphs. */
    public function portableText(mixed $body): string
    {
        if (! is_array($body)) {
            return '';
        }

        $lines = [];
        foreach ($body as $block) {
            if (! is_array($block)) {
                continue;
            }
            $spans = [];
            foreach ($block['children'] ?? [] as $span) {
                if (is_array($span) && isset($span['text'])) {
                    $spans[] = (string) $span['text'];
                }
            }
            $line = implode('', $spans);
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    public function label(array $node): string
    {
        return (string) ($node['label'] ?? $node['title'] ?? 'Section');
    }
}
