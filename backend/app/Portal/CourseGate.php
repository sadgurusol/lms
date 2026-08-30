<?php

namespace App\Portal;

use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single place the public portal decides what's visible. Published + not
 * archived is the baseline; `visibility` refines it:
 *   - public   → listed in the catalogue and reachable
 *   - unlisted → reachable by direct link, not listed
 *   - private  → hidden from the portal entirely
 *
 * Everything defaults to public, so nothing is gated until an author opts in.
 */
class CourseGate
{
    /** Published, not archived — the baseline every portal course must clear. */
    private function base(): Builder
    {
        return Course::query()
            ->whereNotNull('latest_publication_id')
            ->where('workflow_state', '!=', Course::STATE_ARCHIVED);
    }

    /** Listed in the catalogue and the sitemap: public only. */
    public function listable(): Builder
    {
        return $this->base()->where('visibility', Course::VIS_PUBLIC);
    }

    /** Reachable by direct link (landing + content): public or unlisted. */
    public function accessibleQuery(): Builder
    {
        return $this->base()->whereIn('visibility', [Course::VIS_PUBLIC, Course::VIS_UNLISTED]);
    }

    /** Whether a loaded course may be served over the portal. */
    public function accessible(Course $course): bool
    {
        return $course->latest_publication_id !== null
            && $course->workflow_state !== Course::STATE_ARCHIVED
            && in_array($course->visibility, [Course::VIS_PUBLIC, Course::VIS_UNLISTED], true);
    }

    /** How many lessons are free (null = all of them). */
    public function freePreview(Course $course): ?int
    {
        $n = $course->free_preview_lessons;

        return $n !== null && $n > 0 ? $n : null;
    }
}
