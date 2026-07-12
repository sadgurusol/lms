<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\EntitlementResolver;
use App\Http\Controllers\Controller;
use App\Models\AssessmentAttempt;
use App\Models\CoursePublication;
use App\Services\Progress\CourseProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The learner's home dashboard: progress across their courses and how their
 * quizzes have gone. Aggregated server-side so the app renders, not computes.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly CourseProgress $progress,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $clientId = $user->currentClientId();

        $courses = $this->resolver->coursesFor($user, $clientId);

        // The resolver returns courses without their publication; fetch them all
        // in one query rather than lazy-loading (which strict mode forbids) or
        // querying per course.
        $publications = CoursePublication::query()
            ->whereIn('id', $courses->pluck('latest_publication_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $courseCards = [];
        $totalSeconds = 0;
        $completedCourses = 0;

        foreach ($courses as $course) {
            $publication = $publications->get($course->latest_publication_id);
            if ($publication === null) {
                continue;
            }

            $summary = $this->progress->summarise($user, $publication);
            $totalSeconds += (int) $summary['seconds_spent'];
            if ($summary['percent'] >= 100.0) {
                $completedCourses++;
            }

            $courseCards[] = [
                'id' => $course->id,
                'title' => $course->title,
                'subject' => $course->subject,
                'percent' => $summary['percent'],
                'completed_nodes' => $summary['completed_nodes'],
                'total_nodes' => $summary['total_nodes'],
                'seconds_spent' => (int) $summary['seconds_spent'],
            ];
        }

        // Sort "continue learning" by most-progressed-but-unfinished first.
        usort($courseCards, function (array $a, array $b) {
            $aDone = $a['percent'] >= 100.0;
            $bDone = $b['percent'] >= 100.0;
            if ($aDone !== $bDone) {
                return $aDone ? 1 : -1;
            }

            return $b['percent'] <=> $a['percent'];
        });

        $graded = AssessmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('state', AssessmentAttempt::GRADED)
            ->with('assessment:id,title')
            ->latest('graded_at')
            ->get();

        return response()->json([
            'stats' => [
                'courses_enrolled' => count($courseCards),
                'courses_completed' => $completedCourses,
                'minutes_spent' => intdiv($totalSeconds, 60),
                'quizzes_taken' => $graded->count(),
                'quizzes_passed' => $graded->where('passed', true)->count(),
                'average_quiz_percentage' => $this->averagePercentage($graded),
            ],
            'courses' => $courseCards,
            'recent_quizzes' => $graded->take(5)->map(fn (AssessmentAttempt $a) => [
                'assessment_title' => $a->assessment->title,
                'score' => (float) $a->score,
                'max_score' => (float) $a->max_score,
                'percentage' => $this->percentage($a),
                'passed' => $a->passed,
                'graded_at' => $a->graded_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** @param Collection<int, AssessmentAttempt> $graded */
    private function averagePercentage($graded): ?float
    {
        $scored = $graded->filter(fn (AssessmentAttempt $a) => (float) $a->max_score > 0);
        if ($scored->isEmpty()) {
            return null;
        }

        return round($scored->avg(fn (AssessmentAttempt $a) => $this->percentage($a)), 1);
    }

    private function percentage(AssessmentAttempt $a): float
    {
        $max = (float) $a->max_score;

        return $max > 0 ? round((float) $a->score / $max * 100, 1) : 0.0;
    }
}
