<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\EntitlementResolver;
use App\Exceptions\NotEntitled;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttemptResource;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Services\Assessments\RecordAnswer;
use App\Services\Assessments\StartAttempt;
use App\Services\Assessments\SubmitAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The learner attempt lifecycle: start (or resume), read, answer, submit.
 *
 * Access is gated by the same EntitlementResolver as the content API — an
 * assessment is only reachable through a course the learner may read. Ownership
 * is separate: an attempt belongs to one learner and no one else may touch it.
 */
class AttemptController extends Controller
{
    public function __construct(private readonly EntitlementResolver $resolver) {}

    public function store(Request $request, Assessment $assessment, StartAttempt $starter): JsonResponse
    {
        $this->assertEntitled($request, $assessment);

        try {
            $attempt = $starter->handle($assessment, $request->user());
        } catch (RuntimeException $e) {
            // "All attempts used", "not published yet", "no questions" — a 422 the
            // app shows the learner, not a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => AttemptResource::forLearner($attempt)], 201);
    }

    public function show(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->assertOwnedAndEntitled($request, $attempt);

        return response()->json(['data' => AttemptResource::forLearner($attempt)]);
    }

    public function answer(Request $request, AssessmentAttempt $attempt, RecordAnswer $recorder): JsonResponse
    {
        $this->assertOwnedAndEntitled($request, $attempt);

        $data = $request->validate([
            'assessment_question_id' => ['required', 'uuid'],
            'response' => ['present', 'array'],
            // The device's clock, clamped server-side; may lag on reconnect.
            'client_answered_at' => ['nullable', 'date'],
        ]);

        try {
            $recorder->handle(
                $attempt,
                $data['assessment_question_id'],
                $data['response'],
                isset($data['client_answered_at']) ? Carbon::parse($data['client_answered_at']) : null,
            );
        } catch (RuntimeException $e) {
            // "Time limit passed", "cannot backtrack", "not part of this attempt".
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['saved' => true]]);
    }

    public function submit(Request $request, AssessmentAttempt $attempt, SubmitAttempt $submitter): JsonResponse
    {
        $this->assertOwnedAndEntitled($request, $attempt);

        try {
            $attempt = $submitter->handle($attempt);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => AttemptResource::forLearner($attempt)]);
    }

    private function assertEntitled(Request $request, Assessment $assessment): void
    {
        $course = $assessment->course()->firstOrFail();
        $clientId = $request->user()->currentClientId();

        if (! $this->resolver->entitles($request->user(), $course, $clientId)) {
            throw NotEntitled::forCourse($course, $clientId);
        }
    }

    /**
     * The attempt must belong to this learner *and* the course must still be
     * entitled — access can be revoked mid-attempt. A stranger's attempt is a
     * 404: its existence is not theirs to learn.
     */
    private function assertOwnedAndEntitled(Request $request, AssessmentAttempt $attempt): void
    {
        abort_unless($attempt->user_id === $request->user()->id, 404);

        $this->assertEntitled($request, $attempt->assessment);
    }
}
