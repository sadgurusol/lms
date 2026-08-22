<?php

namespace App\ContentBlocks;

/**
 * The block-type registry.
 *
 * One table for all blocks, one JSON Schema per type. Seven joins to render a
 * page and a migration per new block type is not a trade worth making — but an
 * unvalidated jsonb column is a landfill, so every payload is checked on write.
 */
enum BlockType: string
{
    case RichText = 'rich_text';
    case Video = 'video';
    case Image = 'image';
    case Attachment = 'attachment';
    case Embed = 'embed';
    case Callout = 'callout';
    // Interactive lesson blocks (ai-platform authored). These reference external
    // URLs in their payload rather than a Media record, so requiredMediaKind()
    // stays null for them.
    case Simulation = 'simulation';
    case Animation = 'animation';
    case AnimatedReveal = 'animated_reveal';
    // Step-level narration: a pre-generated voice-over clip (url) + its transcript.
    case Audio = 'audio';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    public function schemaPath(): string
    {
        return __DIR__."/schemas/{$this->value}.json";
    }

    /**
     * The media kind this block must reference, if any.
     *
     * Enforced so an `image` block cannot point at a 500 MB lecture video and a
     * `video` block cannot point at a PDF.
     */
    public function requiredMediaKind(): ?string
    {
        return match ($this) {
            self::Video => 'video',
            self::Image => 'image',
            self::Attachment => 'document',
            default => null,
        };
    }

    /** Whether a publish-time readiness check applies (media must be transcoded). */
    public function requiresReadyMedia(): bool
    {
        return $this->requiredMediaKind() !== null;
    }
}
