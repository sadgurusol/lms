<?php

namespace App\Models\Concerns;

use App\Models\Course;
use App\Models\CourseGrant;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

trait HasCourseGrants
{
    /** @return HasMany<CourseGrant, $this> */
    public function courseGrants(): HasMany
    {
        return $this->hasMany(CourseGrant::class);
    }

    /**
     * @param  string|list<string>  $roles
     */
    public function hasGrantOn(Course|string $course, string|array $roles): bool
    {
        $courseId = $course instanceof Course ? $course->id : $course;

        return array_intersect((array) $roles, $this->grantsByCourse()[$courseId] ?? []) !== [];
    }

    /** @return array<string, list<string>> */
    public function grantsByCourse(): array
    {
        return Cache::remember(
            self::grantCacheKey($this->id),
            now()->addMinutes(10),
            fn () => $this->courseGrants()
                ->get()
                ->groupBy('course_id')
                ->map(fn ($grants) => $grants->pluck('role')->all())
                ->all(),
        );
    }

    public static function grantCacheKey(string $userId): string
    {
        return "grants:{$userId}";
    }

    /**
     * Must be called on every course_grants write. A stale grant cache is a
     * ten-minute window in which a revoked author can still edit.
     */
    public function forgetCachedGrants(): void
    {
        Cache::forget(self::grantCacheKey($this->id));
    }
}
