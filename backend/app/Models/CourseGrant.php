<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * The scope axis: user U is `author` (or `reviewer`, or `owner`) on course C.
 *
 * @property string $id
 * @property string $user_id
 * @property string $course_id
 * @property string $role
 * @property string|null $granted_by
 */
#[Fillable(['user_id', 'course_id', 'role', 'granted_by'])]
class CourseGrant extends Model
{
    use HasUuids;

    public const OWNER = 'owner';

    public const AUTHOR = 'author';

    public const REVIEWER = 'reviewer';

    /** Grants that confer edit authority over the draft tree. */
    public const EDITING = [self::OWNER, self::AUTHOR];

    /**
     * The grant cache busts itself.
     *
     * Leaving invalidation to whoever writes the row means a revoked author
     * keeps editing for the ten minutes the cache lives — and nobody notices
     * until it matters.
     */
    protected static function booted(): void
    {
        $forget = fn (CourseGrant $grant) => Cache::forget(User::grantCacheKey($grant->user_id));

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Revoke a grant.
     *
     * Always go through this rather than `CourseGrant::where(...)->delete()`.
     * A mass delete is a single SQL statement: it fires no model events, so the
     * `booted()` hook above never runs and the revoked user keeps their cached
     * grant until the TTL expires. Same class of hole as a raw query-builder
     * insert bypassing a saving hook.
     */
    public static function revoke(User $user, Course $course, ?string $role = null): int
    {
        $grants = static::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->when($role, fn ($q) => $q->where('role', $role))
            ->get();

        $grants->each->delete();

        return $grants->count();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
