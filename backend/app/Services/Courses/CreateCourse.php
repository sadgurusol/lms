<?php

namespace App\Services\Courses;

use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\SchemaVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a course and makes its creator the owner.
 *
 * The grant is not optional. CoursePolicy::view() admits a user who holds
 * `course.view.any` *or* a grant on the course; a content author holds only
 * `course.view.granted`, so without the grant they would create a course and be
 * unable to open it. The two writes therefore share a transaction.
 */
final class CreateCourse
{
    /** @param  array<string, mixed>  $attributes */
    public function handle(array $attributes, SchemaVersion $version, User $actor): Course
    {
        // A draft schema version's levels are still moving. `trg_courses_schema_pinned`
        // forbids re-pointing a course afterwards, so a course bound to a draft
        // would be stranded on whatever that draft eventually became.
        if (! $version->isPublished()) {
            throw new RuntimeException('A course may only be built on a published schema version.');
        }

        return DB::transaction(function () use ($attributes, $version, $actor) {
            $course = Course::create([
                ...$attributes,
                'schema_version_id' => $version->id,
                'workflow_state' => Course::STATE_DRAFT,
                'created_by' => $actor->id,
            ]);

            CourseGrant::create([
                'user_id' => $actor->id,
                'course_id' => $course->id,
                'role' => CourseGrant::OWNER,
            ]);

            return $course;
        });
    }
}
