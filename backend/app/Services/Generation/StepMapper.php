<?php

namespace App\Services\Generation;

use App\ContentBlocks\BlockType;

/**
 * Maps ONE ai-platform Step (native block shape) onto the LMS ContentBlocks a
 * Step node should carry (docs/14 §5). Returns an ordered list of
 * `['type' => …, 'payload' => …]` — the caller attaches them via BlockEditor and
 * validates each against its JSON Schema.
 *
 * Rule (mirrors ischool's "media wins, else animated reveal, else text"):
 *   - an animated reveal becomes an `animated_reveal` block (its fragments carry
 *     the teaching text); otherwise the text/formula blocks become one
 *     `rich_text` block;
 *   - every simulation/animation block is added as its own block.
 *   - a platform `image` block becomes an `image` spec carrying its source URL
 *     (`src`); {@see LessonExpander} ingests that into a Media record (via
 *     {@see ImageIngestor}) and attaches the real `image` block.
 */
final class StepMapper
{
    private const EFFECTS = ['fade', 'slide-up', 'slide-left', 'zoom', 'typewriter'];

    public function __construct(private readonly ContentWriter $content) {}

    /**
     * @param  array<string, mixed>  $step
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public function blocksFor(array $step): array
    {
        $out = [];
        $blocks = is_array($step['blocks'] ?? null) ? $step['blocks'] : [];

        // Primary text carrier: animated reveal, else rich_text from text blocks.
        $animation = $this->cleanAnimation($step['animation'] ?? null, (string) ($step['voice_script'] ?? ''));
        if ($animation !== null) {
            $out[] = ['type' => BlockType::AnimatedReveal->value, 'payload' => $animation];
        } else {
            $text = $this->textFrom($blocks);
            if ($text !== '') {
                $out[] = ['type' => BlockType::RichText->value, 'payload' => [
                    'format' => 'portable_text',
                    'body' => $this->content->toPortableText($text),
                ]];
            }
        }

        // Interactive media blocks (added regardless of the text carrier).
        foreach ($blocks as $b) {
            if (! is_array($b)) {
                continue;
            }
            $type = $b['type'] ?? null;

            if ($type === 'simulation' && ! empty($b['embed_url'])) {
                $out[] = ['type' => BlockType::Simulation->value, 'payload' => array_filter([
                    'url' => (string) $b['embed_url'],
                    'title' => trim((string) ($step['title'] ?? '')) ?: null,
                ], fn ($v) => $v !== null)];
            } elseif ($type === 'animation' && ! empty($b['url'])) {
                $out[] = ['type' => BlockType::Animation->value, 'payload' => ['url' => (string) $b['url']]];
            } elseif ($type === 'diagram' && ($svg = $this->content->cleanSvg((string) ($b['svg'] ?? ''))) !== null) {
                // Model-authored inline SVG. Self-contained (no Media record) —
                // attach directly; the diagram schema validates it on save.
                $out[] = ['type' => BlockType::Diagram->value, 'payload' => array_filter([
                    'format' => 'svg',
                    'svg' => $svg,
                    'alt' => trim((string) ($b['alt'] ?? $step['title'] ?? '')) ?: 'Diagram',
                    'caption' => trim((string) ($b['caption'] ?? '')) ?: null,
                ], fn ($v) => $v !== null)];
            } elseif ($type === 'image' && ($src = $this->imageSource($b)) !== null) {
                // Not yet a valid image-block payload — it needs a Media record.
                // `src` is an ingestion request the caller (LessonExpander)
                // resolves into a media_id before attaching the block.
                $out[] = ['type' => BlockType::Image->value, 'payload' => array_filter([
                    'src' => $src,
                    'alt' => trim((string) ($b['alt'] ?? $b['caption'] ?? $step['title'] ?? '')) ?: 'Illustration',
                    'caption' => trim((string) ($b['caption'] ?? '')) ?: null,
                ], fn ($v) => $v !== null)];
            }
        }

        // Step-level narration. An animated reveal already narrates per fragment,
        // so this only applies to non-reveal steps (media/text). The audio may
        // arrive as a top-level `audio_url` (builder "generate voice") or as an
        // `audio` block from the platform's batch generation. We keep the
        // transcript too, so a player without the clip can still speak it.
        if ($animation === null) {
            $audioUrl = trim((string) ($step['audio_url'] ?? ''));
            if ($audioUrl === '') {
                foreach ($blocks as $b) {
                    if (is_array($b) && ($b['type'] ?? null) === 'audio' && ! empty($b['url'])) {
                        $audioUrl = trim((string) $b['url']);
                        break;
                    }
                }
            }
            $transcript = trim((string) ($step['voice_script'] ?? ''));
            if ($audioUrl !== '' || $transcript !== '') {
                $out[] = ['type' => BlockType::Audio->value, 'payload' => array_filter([
                    'url' => $audioUrl ?: null,
                    'transcript' => $transcript ?: null,
                ], fn ($v) => $v !== null)];
            }
        }

        return $out;
    }

    /**
     * The image source from a platform image block: a hosted URL or an inline
     * data: URI. Tolerant of the field name the platform uses.
     *
     * @param  array<string, mixed>  $b
     */
    private function imageSource(array $b): ?string
    {
        foreach (['url', 'src', 'image_url', 'data'] as $key) {
            $value = trim((string) ($b[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Flatten text + formula blocks into lightly-marked text for Portable Text. */
    private function textFrom(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (($b['type'] ?? null) === 'text' && ! empty($b['markdown'])) {
                $parts[] = trim((string) $b['markdown']);
            } elseif (($b['type'] ?? null) === 'formula' && ! empty($b['latex'])) {
                $parts[] = '$$'.trim((string) $b['latex']).'$$';
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Validate/normalise the platform's animation object into an `animated_reveal`
     * payload, or null if unusable.
     *
     * @return array<string, mixed>|null
     */
    private function cleanAnimation(mixed $anim, string $voiceScript): ?array
    {
        if (! is_array($anim) || ! is_array($anim['fragments'] ?? null)) {
            return null;
        }

        $fragments = [];
        foreach ($anim['fragments'] as $f) {
            if (! is_array($f)) {
                continue;
            }
            $md = trim((string) ($f['md'] ?? ''));
            if ($md === '') {
                continue;
            }
            $effect = in_array($f['effect'] ?? null, self::EFFECTS, true) ? $f['effect'] : 'fade';
            $duration = (int) ($f['duration_ms'] ?? 500);
            // Per-beat visual: an inline SVG (re-sanitized) and/or an image/gif URL,
            // revealed with the beat.
            $svg = $this->content->cleanSvg((string) ($f['svg'] ?? ''));
            $fragments[] = array_filter([
                'md' => $md,
                'effect' => $effect,
                'voice' => trim((string) ($f['voice'] ?? '')) ?: null,
                // Pre-generated narration audio (mp3). Players prefer it over
                // on-device speech synthesis when present.
                'audio_url' => trim((string) ($f['audio_url'] ?? '')) ?: null,
                'svg' => $svg,
                'image_url' => trim((string) ($f['image_url'] ?? '')) ?: null,
                'alt' => trim((string) ($f['alt'] ?? '')) ?: null,
                'duration_ms' => max(100, min($duration, 6000)),
            ], fn ($v) => $v !== null);
        }

        if ($fragments === []) {
            return null;
        }

        return array_filter([
            'voice_script' => trim($voiceScript) ?: null,
            'fragments' => $fragments,
        ], fn ($v) => $v !== null);
    }
}
