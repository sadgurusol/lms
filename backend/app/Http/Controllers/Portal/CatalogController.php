<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Portal\Category;
use App\Portal\CourseGate;
use Illuminate\Http\JsonResponse;

/**
 * The public catalogue: published courses anyone can browse without signing in.
 * Metadata only (no snapshot payload) so the list stays light; the landing and
 * player endpoints load the course itself.
 */
class CatalogController extends Controller
{
    public function index(CourseGate $gate): JsonResponse
    {
        $courses = $gate->listable()
            ->with(['latestPublication' => fn ($q) => $q->select('id', 'lessons_count')])
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'subject', 'category', 'grade_band', 'language', 'latest_publication_id']);

        $categories = $this->categoryFacet($courses);

        return response()->json([
            'data' => $courses->map(fn (Course $c) => [
                'slug' => $c->slug ?: $c->id,
                'title' => $c->title,
                'subject' => $c->subject,
                'category' => $c->category,
                'grade_band' => $c->grade_band,
                'language' => $c->language,
                'lessons' => (int) ($c->latestPublication->lessons_count ?? 0),
            ])->all(),
            // Handy facets for the browse filters and home groupings.
            'categories' => $categories,
            'subjects' => $courses->pluck('subject')->filter()->unique()->sort()->values()->all(),
            'grade_bands' => $courses->pluck('grade_band')->filter()->unique()->sort()->values()->all(),
        ]);
    }

    /** Just the category facet — lightweight, for the top-bar menu. */
    public function categories(CourseGate $gate): JsonResponse
    {
        $courses = $gate->listable()->get(['category']);

        return response()->json(['categories' => $this->categoryFacet($courses)]);
    }

    /**
     * Categories present among $courses, with counts, in canonical display order.
     *
     * @param  \Illuminate\Support\Collection<int, Course>  $courses
     * @return list<array{value: string, label: string, count: int}>
     */
    private function categoryFacet(\Illuminate\Support\Collection $courses): array
    {
        $counts = $courses->countBy(fn (Course $c) => $c->category ?? '');

        return collect(Category::LABELS)
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
                'count' => (int) $counts->get($value, 0),
            ])
            ->filter(fn (array $c) => $c['count'] > 0)
            ->values()
            ->all();
    }
}
