<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSchema;
use App\Models\ReviewRequest;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'schemas' => CourseSchema::count(),
                'courses' => Course::count(),
                'awaiting_review' => ReviewRequest::where('state', ReviewRequest::STATE_OPEN)->count(),
                'published' => Course::whereNotNull('latest_publication_id')->count(),
            ],
        ]);
    }
}
