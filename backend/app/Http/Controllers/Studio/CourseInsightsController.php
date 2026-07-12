<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Insights\CourseInsights;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cohort performance for a course. Anyone who can view the course can read its
 * insights; the numbers are aggregate and every learner row is de-identified,
 * so there is no PII to gate more tightly. See {@see CourseInsights}.
 */
class CourseInsightsController extends Controller
{
    public function show(Request $request, Course $course, CourseInsights $insights): Response
    {
        Gate::authorize('view', $course);

        return Inertia::render('courses/Insights', [
            'course' => ['id' => $course->id, 'title' => $course->title],
            'published' => $course->latest_publication_id !== null,
            ...$insights->for($course),
        ]);
    }
}
