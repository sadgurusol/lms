<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\EntitlementResolver;
use App\Exceptions\InvalidProgressEvent;
use App\Exceptions\NotEntitled;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Services\Progress\CourseProgress;
use App\Services\Progress\RecordProgress;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgressController extends Controller
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly RecordProgress $recorder,
        private readonly CourseProgress $summary,
    ) {}

    public function show(Request $request, Course $course): JsonResponse
    {
        $clientId = $request->user()->currentClientId();

        if (! $this->resolver->entitles($request->user(), $course, $clientId)) {
            throw NotEntitled::forCourse($course, $clientId);
        }

        return response()->json(
            $this->summary->summarise($request->user(), $course->latestPublication()->firstOrFail())
        );
    }

    /**
     * Batch flush from the client's offline outbox.
     *
     * Partial success is the normal case, not an error: one malformed event
     * must not reject a batch containing an hour of a learner's work. The
     * response reports each event so the client can drain its outbox precisely
     * and retry only what failed.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.publication_id' => ['required', 'uuid'],
            'events.*.node_id' => ['required', 'uuid'],
            'events.*.state' => ['sometimes', Rule::in(['not_started', 'in_progress', 'completed'])],
            'events.*.seconds_spent' => ['sometimes', 'integer', 'min:0'],
            'events.*.last_position' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'events.*.client_updated_at' => ['sometimes', 'date'],
        ]);

        $results = [];
        $publications = [];

        foreach ($data['events'] as $index => $event) {
            try {
                $publication = $publications[$event['publication_id']]
                    ??= $this->authorisedPublication($request, $event['publication_id']);

                $this->recorder->handle($request->user(), $publication, $event);

                $results[] = ['index' => $index, 'status' => 'accepted'];
            } catch (InvalidProgressEvent|NotEntitled|ModelNotFoundException $e) {
                // Client-fixable. A database or connection failure is ours, is
                // not caught here, and fails the request loudly.
                $results[] = ['index' => $index, 'status' => 'rejected', 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results], 202);
    }

    /**
     * A learner may write progress only against a publication of a course they
     * are entitled to. Without this, `publication_id` is an unauthenticated
     * pointer into anyone's course.
     */
    private function authorisedPublication(Request $request, string $publicationId): CoursePublication
    {
        $publication = CoursePublication::findOrFail($publicationId);
        $clientId = $request->user()->currentClientId();

        if (! $this->resolver->entitles($request->user(), $publication->course, $clientId)) {
            throw NotEntitled::forCourse($publication->course, $clientId);
        }

        return $publication;
    }
}
