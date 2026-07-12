<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AttemptAnswer;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\Question;
use App\Models\User;
use App\Services\Assessments\RecordAnswer;
use App\Services\Assessments\StartAttempt;
use App\Services\Assessments\SubmitAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Fabricate a cohort on the demo course so its studio Insights page has
 * something to show. Dev-only; re-runnable (it clears its own cohort first).
 *
 * Run `php artisan db:seed --class=DemoContentSeeder` first to build DEMO-101.
 */
class InsightsDemoCommand extends Command
{
    protected $signature = 'insights:demo {--learners=16 : How many cohort learners to fabricate}';

    protected $description = 'Populate the demo course with cohort activity for the Insights page';

    /** Every fabricated learner carries this name prefix so a re-run can find them. */
    private const NAME_PREFIX = 'Demo Cohort ';

    public function handle(StartAttempt $start, RecordAnswer $record, SubmitAttempt $submit): int
    {
        if (! app()->environment('local')) {
            $this->error('This command only runs in the local environment.');

            return self::FAILURE;
        }

        $course = Course::where('code', 'DEMO-101')->whereNotNull('latest_publication_id')->first();
        if ($course === null) {
            $this->error('Published demo course not found. Run: php artisan db:seed --class=DemoContentSeeder');

            return self::FAILURE;
        }

        $publication = CoursePublication::findOrFail($course->latest_publication_id);
        $lessonId = $publication->snapshot['tree'][0]['children'][0]['id'];

        $quiz = Assessment::where('course_id', $course->id)->firstOrFail();
        $answerKey = $this->answerKey($quiz);

        $this->purgePriorCohort();

        // profile = [completed?, seconds, quizCorrect (of 2, or null for no attempt), b2b?]
        $profiles = $this->profiles((int) $this->option('learners'));

        foreach ($profiles as $i => [$completed, $seconds, $correct, $b2b]) {
            $learner = $this->makeLearner($i, $b2b);

            NodeProgress::create([
                'user_id' => $learner->id,
                'publication_id' => $publication->id,
                'course_node_id' => $lessonId,
                'state' => $completed ? NodeProgress::COMPLETED : NodeProgress::IN_PROGRESS,
                'seconds_spent' => $seconds,
                'completed_at' => $completed ? now() : null,
            ]);

            if ($correct !== null) {
                $attempt = $start->handle($quiz, $learner);
                foreach ($attempt->question_order as $index => $questionId) {
                    $shouldBeRight = $index < $correct;
                    $record->handle($attempt, $questionId, $answerKey[$questionId][$shouldBeRight ? 'right' : 'wrong']);
                }
                $submit->handle($attempt);
            }
        }

        $this->info(sprintf('Fabricated %d learners on "%s".', count($profiles), $course->title));
        $this->line("  Open: <comment>/studio/courses/{$course->id}/insights</comment>");

        return self::SUCCESS;
    }

    /**
     * A right and wrong response for each question in the quiz, keyed by its
     * assessment_question id (which is what an attempt's question_order holds).
     *
     * @return array<string, array{right: array<string, mixed>, wrong: array<string, mixed>}>
     */
    private function answerKey(Assessment $quiz): array
    {
        $key = [];

        $quiz->loadMissing('assessmentQuestions.question.options');

        foreach ($quiz->assessmentQuestions as $aq) {
            /** @var Question $question */
            $question = $aq->question;
            $options = $question->options;

            if ($options->isNotEmpty()) {
                $correct = $options->firstWhere('is_correct', true) ?? $options->first();
                $wrong = $options->firstWhere('is_correct', false) ?? $options->first();
                $key[$aq->id] = [
                    'right' => ['option_id' => $correct->id],
                    'wrong' => ['option_id' => $wrong->id],
                ];

                continue;
            }

            // Optionless types (true/false) carry the answer in `grading`.
            $answer = (bool) ($question->grading['answer'] ?? true);
            $key[$aq->id] = [
                'right' => ['answer' => $answer],
                'wrong' => ['answer' => ! $answer],
            ];
        }

        return $key;
    }

    /**
     * A spread of learners: strong finishers, middling, at-risk, and browsers,
     * with roughly a third launched from a school (B2B).
     *
     * @return list<array{0: bool, 1: int, 2: int|null, 3: bool}>
     */
    private function profiles(int $count): array
    {
        $templates = [
            [true, 1500, 2, false],  // finished, aced it
            [true, 1320, 2, true],   // finished, aced it (school)
            [true, 900, 1, false],   // finished, half the quiz
            [true, 780, 1, true],
            [true, 1100, 2, false],
            [false, 640, 1, false],  // still reading, half the quiz
            [false, 520, 0, true],   // still reading, failed — at risk
            [false, 480, 0, false],  // at risk
            [true, 960, 2, false],
            [false, 300, 0, true],   // at risk
            [true, 1200, 1, false],
            [false, 180, null, false], // just browsing, no quiz yet
            [false, 90, null, true],   // just browsing
            [true, 1440, 2, false],
            [false, 700, 1, true],
            [true, 840, 2, false],
        ];

        $profiles = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $profiles[] = $templates[$i % count($templates)];
        }

        return $profiles;
    }

    private function makeLearner(int $index, bool $b2b): User
    {
        $name = self::NAME_PREFIX.($b2b ? 'S' : 'B').str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

        if ($b2b) {
            return User::create([
                'name' => $name,
                'kind' => User::KIND_CLIENT_PROVISIONED,
                'status' => 'active',
            ]);
        }

        return User::create([
            'name' => $name,
            'email' => 'demo-cohort-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'password',
            'kind' => User::KIND_LOCAL,
            'status' => 'active',
        ]);
    }

    /** Remove any cohort from a previous run so re-running stays clean. */
    private function purgePriorCohort(): void
    {
        $userIds = User::where('name', 'like', self::NAME_PREFIX.'%')->pluck('id');
        if ($userIds->isEmpty()) {
            return;
        }

        $attemptIds = AssessmentAttempt::whereIn('user_id', $userIds)->pluck('id');
        AttemptAnswer::whereIn('attempt_id', $attemptIds)->delete();
        AssessmentAttempt::whereIn('user_id', $userIds)->delete();
        NodeProgress::whereIn('user_id', $userIds)->delete();
        User::whereIn('id', $userIds)->forceDelete();
    }
}
