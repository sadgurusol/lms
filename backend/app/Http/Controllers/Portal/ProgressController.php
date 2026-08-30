<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\PortalEnrollment;
use App\Models\User;
use App\Portal\CourseGate;
use App\Services\Progress\RecordProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Signed-in learner state on the portal: cross-device lesson progress (reusing
 * the app's NodeProgress/RecordProgress, but gated by the public CourseGate
 * instead of paid entitlement) and the "My learning" enrollment list.
 */
class ProgressController extends Controller
{
    public function __construct(
        private readonly CourseGate $gate,
        private readonly RecordProgress $recorder,
    ) {}

    /** Completed lesson node-ids for the signed-in learner. */
    public function progress(Request $request, Course $course): JsonResponse
    {
        $publication = $this->publication($course);

        $completed = NodeProgress::query()
            ->where('user_id', $request->user()->id)
            ->where('publication_id', $publication->id)
            ->where('state', NodeProgress::COMPLETED)
            ->pluck('course_node_id');

        return response()->json(['completed' => $completed]);
    }

    /** Record a lesson as completed (and auto-enroll on first activity). */
    public function record(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'uuid'],
            'state' => ['sometimes', Rule::in([NodeProgress::IN_PROGRESS, NodeProgress::COMPLETED])],
        ]);

        $publication = $this->publication($course);

        $this->recorder->handle($request->user(), $publication, [
            'node_id' => $data['node_id'],
            'state' => $data['state'] ?? NodeProgress::COMPLETED,
        ]);

        $this->ensureEnrolled($request->user(), $course);

        return response()->json(['ok' => true]);
    }

    /** Explicitly add a course to "My learning". */
    public function enroll(Request $request, Course $course): JsonResponse
    {
        $this->publication($course); // 404 unless publicly accessible
        $this->ensureEnrolled($request->user(), $course);

        return response()->json(['ok' => true]);
    }

    /** The learner's enrolled courses with a progress count. */
    public function myCourses(Request $request): JsonResponse
    {
        $enrollments = PortalEnrollment::query()
            ->where('user_id', $request->user()->id)
            ->with(['course' => fn ($q) => $q->with(['latestPublication' => fn ($p) => $p->select('id', 'lessons_count')])])
            ->latest()
            ->get()
            ->filter(fn (PortalEnrollment $e) => $e->course !== null && $this->gate->accessible($e->course));

        // One grouped count query, so the list is not N+1.
        $doneByPublication = NodeProgress::query()
            ->where('user_id', $request->user()->id)
            ->where('state', NodeProgress::COMPLETED)
            ->selectRaw('publication_id, count(*) as c')
            ->groupBy('publication_id')
            ->pluck('c', 'publication_id');

        return response()->json([
            'data' => $enrollments->map(function (PortalEnrollment $e) use ($doneByPublication) {
                $course = $e->course;

                return [
                    'slug' => $course->slug ?: $course->id,
                    'title' => $course->title,
                    'subject' => $course->subject,
                    'grade_band' => $course->grade_band,
                    'language' => $course->language,
                    'lessons' => (int) ($course->latestPublication->lessons_count ?? 0),
                    'done' => (int) ($doneByPublication[$course->latest_publication_id] ?? 0),
                ];
            })->values(),
        ]);
    }

    private function publication(Course $course): CoursePublication
    {
        if (! $this->gate->accessible($course)) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $course->latestPublication()->firstOrFail();
    }

    private function ensureEnrolled(User $user, Course $course): void
    {
        PortalEnrollment::firstOrCreate(['user_id' => $user->id, 'course_id' => $course->id]);
    }
}
