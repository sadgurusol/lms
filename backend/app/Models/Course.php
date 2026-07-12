<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A course: an instance of a schema version, plus its draft content tree.
 *
 * @property string $id
 * @property string $title
 * @property string|null $code
 * @property string|null $subject
 * @property string|null $grade_band
 * @property string $language
 * @property string $schema_version_id
 * @property string $workflow_state
 * @property string|null $latest_publication_id
 * @property string|null $created_by
 * @property-read SchemaVersion $schemaVersion
 */
#[Fillable([
    'title', 'code', 'subject', 'grade_band', 'language',
    'schema_version_id', 'workflow_state', 'created_by',
])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    public const STATE_DRAFT = 'draft';

    public const STATE_IN_REVIEW = 'in_review';

    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const STATE_APPROVED = 'approved';

    public const STATE_PUBLISHED = 'published';

    public const STATE_ARCHIVED = 'archived';

    /**
     * A published course is read-only: its content is a frozen snapshot learners
     * are reading. To change it you start a new version (revise), which returns
     * the course to draft. An archived course is closed. Every other state is a
     * working draft in some phase of review, and is editable.
     *
     * This is a property of the course's *state*, not of the actor — even an
     * admin edits through a new version, never over a live publication. So the
     * mutation controllers check this in addition to the permission policy.
     */
    public function isEditable(): bool
    {
        return in_array($this->workflow_state, [
            self::STATE_DRAFT,
            self::STATE_CHANGES_REQUESTED,
            self::STATE_IN_REVIEW,
            self::STATE_APPROVED,
        ], true);
    }

    /** @return BelongsTo<SchemaVersion, $this> */
    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(SchemaVersion::class);
    }

    /**
     * Touching an approved or published course diverges the draft tree from the
     * latest publication, so the approval no longer means anything and the
     * course is a draft again. `latest_publication_id` stays exactly where it
     * is — learners are reading it, and they must not notice.
     *
     * Editing during `in_review` is fine and does NOT reset the state: authors
     * fix the typos the reviewer just flagged, and freezing the tree mid-review
     * causes more pain than it prevents.
     */
    public function markDraftDiverged(): void
    {
        if (in_array($this->workflow_state, [self::STATE_APPROVED, self::STATE_PUBLISHED], true)) {
            $this->forceFill(['workflow_state' => self::STATE_DRAFT])->save();
        }
    }

    /** @return HasMany<CourseNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(CourseNode::class);
    }

    /** @return HasMany<Assessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /** @return HasMany<CoursePublication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(CoursePublication::class)->orderBy('number');
    }

    /** @return BelongsTo<CoursePublication, $this> */
    public function latestPublication(): BelongsTo
    {
        return $this->belongsTo(CoursePublication::class, 'latest_publication_id');
    }

    /** @return HasMany<ReviewRequest, $this> */
    public function reviewRequests(): HasMany
    {
        return $this->hasMany(ReviewRequest::class);
    }

    /** @return HasMany<CourseGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(CourseGrant::class);
    }

    /** @return HasMany<CourseNode, $this> */
    public function rootNodes(): HasMany
    {
        return $this->nodes()->whereNull('parent_id')->orderBy('sort_key');
    }

    /**
     * The levels a node may take directly under the course root.
     *
     * This is what drives "+ Add Part" in the editor. The UI hardcodes no level
     * name; it renders whatever the schema says, which is why adding a schema
     * needs no frontend change.
     *
     * @return Collection<int, SchemaLevel>
     */
    public function allowedRootLevels(): Collection
    {
        return $this->schemaVersion->rootLevels()->get();
    }
}
