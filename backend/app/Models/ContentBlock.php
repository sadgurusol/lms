<?php

namespace App\Models;

use App\ContentBlocks\BlockPayloadValidator;
use App\ContentBlocks\BlockType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A unit of content on a node: rich text, a video, an image, an attachment.
 *
 * One table, not one per type. Seven joins to render a page and a migration per
 * new block type is not a trade worth making. `payload` is validated against a
 * per-type JSON Schema on write — an unvalidated jsonb column is a landfill.
 *
 * Postgres enforces which *types* a node's level permits (trigger). It cannot
 * read a JSON Schema, so payload shape is enforced in the saving hook — not in a
 * FormRequest, which a seeder or a queue job would bypass.
 *
 * @property string $id
 * @property string $course_node_id
 * @property string $type
 * @property string $sort_key
 * @property array<string, mixed> $payload
 * @property string|null $media_id
 * @property-read CourseNode $courseNode
 * @property-read Media|null $media
 */
#[Fillable(['course_node_id', 'type', 'sort_key', 'payload', 'media_id', 'created_by'])]
class ContentBlock extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_RICH_TEXT = 'rich_text';

    public const TYPE_VIDEO = 'video';

    public const TYPE_IMAGE = 'image';

    public const TYPE_ATTACHMENT = 'attachment';

    public const TYPE_EMBED = 'embed';

    public const TYPE_CALLOUT = 'callout';

    protected static function booted(): void
    {
        static::saving(function (ContentBlock $block) {
            app(BlockPayloadValidator::class)->validate(
                $block->blockType(),
                $block->payload ?? [],
                $block->media_id,
            );
        });

        // Editing content diverges the draft from the latest publication.
        $diverge = fn (ContentBlock $block) => $block->courseNode->course->markDraftDiverged();

        static::saved($diverge);
        static::deleted($diverge);
    }

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function blockType(): BlockType
    {
        return BlockType::from($this->type);
    }

    /** @return BelongsTo<CourseNode, $this> */
    public function courseNode(): BelongsTo
    {
        return $this->belongsTo(CourseNode::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
