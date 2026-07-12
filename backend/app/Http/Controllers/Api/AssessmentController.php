<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\EntitlementResolver;
use App\Exceptions\NotEntitled;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The assessments a learner can take on a course. Assessments are not part of
 * the published snapshot, so this is how the app discovers "there is a quiz on
 * this topic" and what the learner has already done with it.
 */
class AssessmentController extends Controller
{
    public function __construct(private readonly EntitlementResolver $resolver) {}

    public function index(Request $request, Course $course): JsonResponse
    {
        $clientId = $request->user()->currentClientId();

        if (! $this->resolver->entitles($request->user(), $course, $clientId)) {
            throw NotEntitled::forCourse($course, $clientId);
        }

        $assessments = $course->assessments()->withCount('assessmentQuestions')->get();

        // One query for every attempt this learner has on these assessments,
        // grouped in memory — not one query per assessment.
        $attempts = AssessmentAttempt::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->get()
            ->groupBy('assessment_id');

        return response()->json([
            'data' => $assessments->map(function (Assessment $assessment) use ($attempts) {
                $config = $assessment->config();
                /** @var Collection<int, AssessmentAttempt> $mine */
                $mine = $attempts->get($assessment->id) ?? collect();

                $graded = $mine->where('state', AssessmentAttempt::GRADED);

                return [
                    'id' => $assessment->id,
                    'node_id' => $assessment->course_node_id,
                    'kind' => $assessment->kind,
                    'title' => $assessment->title,
                    'question_count' => $assessment->assessment_questions_count,
                    'total_points' => (float) $assessment->total_points,
                    'settings' => [
                        'time_limit_s' => $config->timeLimitSeconds,
                        'max_attempts' => $config->maxAttempts,
                        'pass_percentage' => $config->passPercentage,
                        'allow_backtrack' => $config->allowBacktrack,
                    ],
                    'my_state' => [
                        'attempts_used' => $mine->count(),
                        'in_progress_attempt_id' => $mine->firstWhere('state', AssessmentAttempt::IN_PROGRESS)?->id,
                        // Best mark so far, as a percentage, across graded attempts.
                        'best_percentage' => $this->bestPercentage($graded),
                        'passed' => $graded->contains(fn (AssessmentAttempt $a) => $a->passed === true),
                        'can_start' => $config->maxAttempts === null || $mine->count() < $config->maxAttempts
                            || $mine->contains(fn (AssessmentAttempt $a) => $a->state === AssessmentAttempt::IN_PROGRESS),
                    ],
                ];
            })->all(),
        ]);
    }

    /** @param Collection<int, AssessmentAttempt> $graded */
    private function bestPercentage($graded): ?float
    {
        $best = null;

        foreach ($graded as $attempt) {
            $max = (float) $attempt->max_score;
            if ($max <= 0) {
                continue;
            }

            $pct = (float) $attempt->score / $max * 100;
            $best = $best === null ? $pct : max($best, $pct);
        }

        return $best === null ? null : round($best, 1);
    }
}
