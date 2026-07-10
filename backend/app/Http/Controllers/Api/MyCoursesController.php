<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\EntitlementResolver;
use App\Exceptions\NotEntitled;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The learner's view of the catalogue.
 *
 * Every route here goes through EntitlementResolver. There is no Eloquent scope
 * shortcut for "just the list endpoint": two implementations of an access rule
 * is one implementation and one paid-content leak.
 */
class MyCoursesController extends Controller
{
    public function __construct(private readonly EntitlementResolver $resolver) {}

    public function index(Request $request): JsonResponse
    {
        $courses = $this->resolver->coursesFor($request->user(), $this->clientId($request));

        return response()->json([
            'data' => $courses->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'code' => $course->code,
                'subject' => $course->subject,
                'grade_band' => $course->grade_band,
                'language' => $course->language,
                'publication_id' => $course->latest_publication_id,
            ])->all(),
        ]);
    }

    /**
     * The published snapshot: everything the client needs to render the course
     * offline, with an ETag so a repeat fetch costs a 304 rather than a payload.
     */
    public function content(Request $request, Course $course): JsonResponse
    {
        $clientId = $this->clientId($request);

        if (! $this->resolver->entitles($request->user(), $course, $clientId)) {
            throw NotEntitled::forCourse($course, $clientId);
        }

        /** @var CoursePublication $publication */
        $publication = $course->latestPublication()->firstOrFail();

        if ($request->header('If-None-Match') === $publication->snapshot_etag) {
            return response()->json(null, 304);
        }

        return response()->json([
            'publication' => [
                'id' => $publication->id,
                'number' => $publication->number,
                'published_at' => $publication->published_at->toIso8601String(),
            ],
            ...$publication->snapshot,
            'media_manifest' => $publication->media_manifest,
        ])->setEtag($publication->snapshot_etag);
    }

    /**
     * The client context, from the authenticated session — never from a request
     * parameter. Until launch sessions exist (M9) this is always null.
     */
    private function clientId(Request $request): ?string
    {
        return $request->user()->currentClientId();
    }
}
