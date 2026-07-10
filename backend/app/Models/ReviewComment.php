<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A threaded comment anchored to the course, a node, or a single block.
 *
 * @property string $id
 * @property string $review_request_id
 * @property string|null $parent_comment_id
 * @property string $author_id
 * @property string $body
 * @property string $anchor_type
 * @property string|null $anchor_id
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 */
#[Fillable(['review_request_id', 'parent_comment_id', 'author_id', 'body', 'anchor_type', 'anchor_id', 'resolved_at', 'resolved_by'])]
class ReviewComment extends Model
{
    use HasUuids;

    public const ANCHOR_COURSE = 'course';

    public const ANCHOR_NODE = 'node';

    public const ANCHOR_BLOCK = 'block';

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<ReviewRequest, $this> */
    public function reviewRequest(): BelongsTo
    {
        return $this->belongsTo(ReviewRequest::class);
    }

    /** @return HasMany<ReviewComment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(ReviewComment::class, 'parent_comment_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
