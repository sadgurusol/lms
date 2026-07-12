<?php

namespace App\Services\Insights;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\User;
use App\Services\Progress\PublicationNodes;
use Illuminate\Support\Collection;

/**
 * Cohort analytics for a course, for its authoring team.
 *
 * A deliberate privacy line runs through this: the studio is the content
 * provider, not the school. B2B learners belong to the client, which receives
 * their individual activity over the reporting webhook — so no learner is named
 * here. Authors see how the *course* performs (completion, time, score shape),
 * with per-learner rows de-identified to an opaque handle.
 */
final class CourseInsights
{
    /** Completion at or below this reads as "at risk" of falling behind. */
    private const AT_RISK_QUIZ_PERCENT = 50.0;

    public function __construct(private readonly PublicationNodes $nodes) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Course $course): array
    {
        $publicationIds = CoursePublication::query()
            ->where('course_id', $course->id)
            ->pluck('id')
            ->all();

        $progress = $publicationIds === []
            ? new Collection
            : NodeProgress::query()->whereIn('publication_id', $publicationIds)->get();

        $trackableTotals = $this->trackableTotals($progress->pluck('publication_id')->unique()->all());

        $assessmentIds = Assessment::query()->where('course_id', $course->id)->pluck('id')->all();

        $attempts = $assessmentIds === []
            ? new Collection
            : AssessmentAttempt::query()
                ->whereIn('assessment_id', $assessmentIds)
                ->where('state', AssessmentAttempt::GRADED)
                ->whereNotNull('graded_at')
                ->with('assessment:id,title,kind')
                ->get();

        $learners = $this->learners($progress, $attempts, $trackableTotals);

        return [
            'summary' => $this->summary($learners, $attempts),
            'score_distribution' => $this->scoreDistribution($attempts),
            'assessments' => $this->perAssessment($course, $attempts),
            'learners' => $learners->values()->all(),
        ];
    }

    /**
     * Trackable-node count for each publication that has any progress, indexed
     * by publication id. Only these snapshots are read, not every publication.
     *
     * @param  list<string>  $publicationIds
     * @return array<string, array{number: int, total: int}>
     */
    private function trackableTotals(array $publicationIds): array
    {
        if ($publicationIds === []) {
            return [];
        }

        return CoursePublication::query()
            ->whereIn('id', $publicationIds)
            ->get(['id', 'number', 'snapshot'])
            ->mapWithKeys(fn (CoursePublication $p) => [
                $p->id => ['number' => $p->number, 'total' => count($this->nodes->trackable($p))],
            ])
            ->all();
    }

    /**
     * One de-identified row per learner: completion against the newest
     * publication they have touched, total time on task, and quiz average.
     *
     * @param  Collection<int, NodeProgress>  $progress
     * @param  Collection<int, AssessmentAttempt>  $attempts
     * @param  array<string, array{number: int, total: int}>  $trackableTotals
     * @return Collection<int, array<string, mixed>>
     */
    private function learners(Collection $progress, Collection $attempts, array $trackableTotals): Collection
    {
        $progressByUser = $progress->groupBy('user_id');
        $attemptsByUser = $attempts->groupBy('user_id');

        $userIds = $progressByUser->keys()->merge($attemptsByUser->keys())->unique();

        // One query to classify B2B vs direct; names are never read.
        $kinds = User::query()->whereIn('id', $userIds->all())->pluck('kind', 'id');

        return $userIds->map(function (string $userId) use ($progressByUser, $attemptsByUser, $trackableTotals, $kinds) {
            /** @var Collection<int, NodeProgress> $rows */
            $rows = $progressByUser->get($userId) ?? new Collection;
            /** @var Collection<int, AssessmentAttempt> $userAttempts */
            $userAttempts = $attemptsByUser->get($userId) ?? new Collection;

            [$completed, $total] = $this->completion($rows, $trackableTotals);
            $percent = $total === 0 ? 0.0 : round($completed / $total * 100, 1);

            $quizAvg = $this->averagePercentage($userAttempts);

            /** @var array<string, mixed> $row */
            $row = [
                'ref' => 'L-'.substr($userId, -4),
                'kind' => $kinds->get($userId) === User::KIND_CLIENT_PROVISIONED ? 'b2b' : 'direct',
                'completion_percent' => $percent,
                'completed_nodes' => $completed,
                'total_nodes' => $total,
                'seconds_spent' => (int) $rows->sum('seconds_spent'),
                'quizzes_taken' => $userAttempts->count(),
                'quiz_average' => $quizAvg,
                'at_risk' => $quizAvg !== null && $quizAvg < self::AT_RISK_QUIZ_PERCENT,
            ];

            return $row;
        })
            // At-risk first, then least-progressed — the rows an author acts on.
            ->sortBy(fn (array $l) => [$l['at_risk'] ? 0 : 1, $l['completion_percent']])
            ->values();
    }

    /**
     * Completed vs. total trackable nodes for a learner, measured against the
     * newest publication they hold progress on.
     *
     * @param  Collection<int, NodeProgress>  $rows
     * @param  array<string, array{number: int, total: int}>  $trackableTotals
     * @return array{int, int}
     */
    private function completion(Collection $rows, array $trackableTotals): array
    {
        if ($rows->isEmpty()) {
            return [0, 0];
        }

        $newest = $rows
            ->map(fn (NodeProgress $p) => $p->publication_id)
            ->unique()
            ->sortByDesc(fn (string $id) => $trackableTotals[$id]['number'] ?? 0)
            ->first();

        $completed = $rows
            ->where('publication_id', $newest)
            ->filter(fn (NodeProgress $p) => $p->isCompleted())
            ->count();

        return [$completed, $trackableTotals[$newest]['total'] ?? 0];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $learners
     * @param  Collection<int, AssessmentAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function summary(Collection $learners, Collection $attempts): array
    {
        $withProgress = $learners->filter(fn (array $l) => $l['total_nodes'] > 0);
        $seconds = $learners->pluck('seconds_spent');

        return [
            'learners' => $learners->count(),
            'completed_course' => $withProgress
                ->filter(fn (array $l) => $l['completion_percent'] >= 100.0)
                ->count(),
            'average_completion' => $withProgress->isEmpty()
                ? 0.0
                : round($withProgress->avg('completion_percent'), 1),
            'at_risk' => $learners->where('at_risk', true)->count(),
            'median_minutes' => intdiv($this->median($seconds), 60),
            'total_minutes' => intdiv((int) $seconds->sum(), 60),
            'quizzes_graded' => $attempts->count(),
            'quiz_average' => $this->averagePercentage($attempts),
            'pass_rate' => $attempts->isEmpty()
                ? null
                : round($attempts->where('passed', true)->count() / $attempts->count() * 100, 1),
        ];
    }

    /**
     * Ten deciles, 0–9 … 90–100. A perfect score lands in the last bucket.
     *
     * @param  Collection<int, AssessmentAttempt>  $attempts
     * @return list<array{label: string, count: int}>
     */
    private function scoreDistribution(Collection $attempts): array
    {
        $buckets = array_fill(0, 10, 0);

        foreach ($attempts as $attempt) {
            $index = (int) min(9, intdiv((int) floor($this->percentage($attempt)), 10));
            $buckets[$index]++;
        }

        return array_map(
            fn (int $i, int $count) => [
                'label' => $i === 9 ? '90–100' : ($i * 10).'–'.($i * 10 + 9),
                'count' => $count,
            ],
            array_keys($buckets),
            $buckets,
        );
    }

    /**
     * Per-assessment breakdown, including assessments no one has finished yet.
     *
     * @param  Collection<int, AssessmentAttempt>  $attempts
     * @return list<array<string, mixed>>
     */
    private function perAssessment(Course $course, Collection $attempts): array
    {
        $byAssessment = $attempts->groupBy('assessment_id');

        return Assessment::query()
            ->where('course_id', $course->id)
            ->orderBy('title')
            ->get(['id', 'title', 'kind'])
            ->map(function (Assessment $assessment) use ($byAssessment) {
                /** @var Collection<int, AssessmentAttempt> $group */
                $group = $byAssessment->get($assessment->id) ?? new Collection;

                return [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'kind' => $assessment->kind,
                    'attempts' => $group->count(),
                    'average' => $this->averagePercentage($group),
                    'pass_rate' => $group->isEmpty()
                        ? null
                        : round($group->where('passed', true)->count() / $group->count() * 100, 1),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, AssessmentAttempt>  $attempts
     */
    private function averagePercentage(Collection $attempts): ?float
    {
        $scored = $attempts->filter(fn (AssessmentAttempt $a) => (float) $a->max_score > 0);

        return $scored->isEmpty() ? null : round($scored->avg(fn (AssessmentAttempt $a) => $this->percentage($a)), 1);
    }

    private function percentage(AssessmentAttempt $attempt): float
    {
        $max = (float) $attempt->max_score;

        return $max > 0 ? round((float) $attempt->score / $max * 100, 1) : 0.0;
    }

    /**
     * @param  Collection<int, int>  $values
     */
    private function median(Collection $values): int
    {
        if ($values->isEmpty()) {
            return 0;
        }

        $sorted = $values->sort()->values();
        $count = $sorted->count();
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (int) $sorted[$mid]
            : (int) round(((int) $sorted[$mid - 1] + (int) $sorted[$mid]) / 2);
    }
}
