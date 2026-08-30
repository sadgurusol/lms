<?php

namespace App\Services\Content;

use App\ContentBlocks\BlockType;
use App\Models\ContentBlock;
use App\Models\CourseNode;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create, reorder and update the content blocks on a node.
 *
 * Sort keys are fractional indices, exactly as in CourseTree: the caller names a
 * neighbour, the server derives the key, so two concurrent inserts never
 * collide. Payload *shape* is not this class's concern — the model's saving hook
 * validates it against the type's JSON Schema, and does so even for a seeder.
 */
final class BlockEditor
{
    /**
     * The block types this editor can author without the media pipeline.
     *
     * image/video/attachment require an uploaded, transcoded asset and belong to
     * the media surface; they are deliberately absent here rather than offered
     * and then broken.
     */
    public const AUTHORABLE = [
        BlockType::RichText->value,
        BlockType::Callout->value,
        BlockType::Embed->value,
    ];

    /**
     * Interactive block types authored in one shot with a full payload (they have
     * no meaningful empty default and are produced by generation, not typed by
     * hand). They carry external URLs / structured data, not a Media record, so
     * they sit outside both AUTHORABLE and MEDIA_TYPES. Created via
     * {@see appendAuthored()}.
     */
    public const GENERATED = [
        BlockType::AnimatedReveal->value,
        BlockType::Simulation->value,
        BlockType::Animation->value,
        BlockType::Audio->value,
        BlockType::Diagram->value,
    ];

    /** Append a new block to the end of the node — the meaning of "add block". */
    public function append(CourseNode $node, string $type): ContentBlock
    {
        return $this->create($node, $type, $this->lastBlockId($node));
    }

    /**
     * A null target head-inserts (sort_key before the first block). Callers that
     * mean "add to the end" should use append; it is what an author expects.
     */
    public function create(CourseNode $node, string $type, ?string $afterBlockId = null): ContentBlock
    {
        if (! in_array($type, self::AUTHORABLE, true)) {
            throw new RuntimeException("A [{$type}] block cannot be authored here.");
        }

        // The node's level must permit this type. The database enforces it too;
        // checking first turns a trigger abort into a clean message.
        if (! $node->permitsBlockType($type)) {
            throw new RuntimeException('This section does not allow that kind of content.');
        }

        return DB::transaction(fn () => ContentBlock::create([
            'course_node_id' => $node->id,
            'type' => $type,
            'sort_key' => $this->sortKeyAfter($node, $afterBlockId),
            'payload' => $this->defaultPayload(BlockType::from($type)),
        ]));
    }

    /** The media-backed block types this editor supports. */
    public const MEDIA_TYPES = [BlockType::Image->value, BlockType::Attachment->value, BlockType::Video->value];

    /**
     * Append a media block (image, attachment, or video) referencing an
     * uploaded asset.
     *
     * @param  array<string, mixed>  $payload  the type's schema fields, minus media_id
     */
    public function appendMedia(CourseNode $node, string $type, string $mediaId, array $payload): ContentBlock
    {
        if (! in_array($type, self::MEDIA_TYPES, true)) {
            throw new RuntimeException("A [{$type}] block cannot be authored here.");
        }

        if (! $node->permitsBlockType($type)) {
            throw new RuntimeException('This section does not allow that kind of content.');
        }

        return DB::transaction(fn () => ContentBlock::create([
            'course_node_id' => $node->id,
            'type' => $type,
            // Both the column and the payload carry media_id; the saving hook
            // rejects a mismatch, so set them from one source.
            'media_id' => $mediaId,
            'sort_key' => $this->sortKeyAfter($node, $this->lastBlockId($node)),
            'payload' => ['media_id' => $mediaId, ...$payload],
        ]));
    }

    /**
     * Create a non-media block with its full payload in one shot (no invalid
     * default is ever inserted). For rich_text/callout/embed and the interactive
     * GENERATED types. The saving hook validates the payload against the schema.
     *
     * @param  array<string, mixed>  $payload
     */
    public function appendAuthored(CourseNode $node, string $type, array $payload): ContentBlock
    {
        if (! in_array($type, [...self::AUTHORABLE, ...self::GENERATED], true)) {
            throw new RuntimeException("A [{$type}] block cannot be authored here.");
        }

        if (! $node->permitsBlockType($type)) {
            throw new RuntimeException('This section does not allow that kind of content.');
        }

        return DB::transaction(fn () => ContentBlock::create([
            'course_node_id' => $node->id,
            'type' => $type,
            'sort_key' => $this->sortKeyAfter($node, $this->lastBlockId($node)),
            'payload' => $payload,
        ]));
    }

    /** @param array<string, mixed> $payload */
    public function updatePayload(ContentBlock $block, array $payload): ContentBlock
    {
        // The saving hook validates; an invalid payload throws InvalidBlockPayload
        // and the transaction rolls back, so a bad edit never half-lands.
        DB::transaction(fn () => $block->update(['payload' => $payload]));

        return $block;
    }

    public function reorder(ContentBlock $block, ?string $afterBlockId): ContentBlock
    {
        $node = $block->courseNode;

        $block->update([
            'sort_key' => $this->sortKeyAfter($node, $afterBlockId, $block->id),
        ]);

        return $block;
    }

    public function delete(ContentBlock $block): void
    {
        $block->delete();
    }

    /** The id of the last block on $node, or null when it has none. */
    private function lastBlockId(CourseNode $node): ?string
    {
        return ContentBlock::query()
            ->where('course_node_id', $node->id)
            // sort_key is COLLATE "C", so this orders byte-wise, as the index does.
            ->orderByDesc('sort_key')
            ->value('id');
    }

    private function sortKeyAfter(CourseNode $node, ?string $afterBlockId, ?string $excludeId = null): string
    {
        $siblings = ContentBlock::query()
            ->where('course_node_id', $node->id)
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->orderBy('sort_key')
            ->pluck('sort_key', 'id');

        if ($afterBlockId === null) {
            return FractionalIndex::between(null, $siblings->first());
        }

        $keys = $siblings->values()->all();
        $ids = $siblings->keys()->all();
        $index = array_search($afterBlockId, $ids, true);

        if ($index === false) {
            throw new RuntimeException("Block {$afterBlockId} is not on this node.");
        }

        return FractionalIndex::between($keys[$index], $keys[$index + 1] ?? null);
    }

    /**
     * A new block starts valid and empty, so it saves on first create and the
     * editor has a real shape to render.
     *
     * @return array<string, mixed>
     */
    private function defaultPayload(BlockType $type): array
    {
        return match ($type) {
            BlockType::RichText => ['format' => 'portable_text', 'body' => []],
            BlockType::Callout => ['variant' => 'info', 'body' => []],
            BlockType::Embed => ['provider' => 'youtube', 'url' => 'https://'],
            default => throw new RuntimeException("No default payload for [{$type->value}]."),
        };
    }
}
