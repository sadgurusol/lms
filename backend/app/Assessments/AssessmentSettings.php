<?php

namespace App\Assessments;

use App\Models\Assessment;

/**
 * Quiz and test defaults, overridable per assessment.
 *
 * A quiz is a formative check inside a Topic: unlimited attempts, no clock, the
 * answers afterwards. A test is graded at a Chapter boundary: one attempt, a
 * timer, and the answer key withheld until you pass.
 */
final class AssessmentSettings
{
    public const SHOW_NEVER = 'never';

    public const SHOW_AFTER_SUBMIT = 'after_submit';

    public const SHOW_AFTER_PASS = 'after_pass';

    private function __construct(
        public readonly ?int $timeLimitSeconds,
        public readonly ?int $maxAttempts,
        public readonly ?float $passPercentage,
        public readonly bool $shuffleQuestions,
        public readonly bool $shuffleOptions,
        public readonly string $showAnswers,
        public readonly bool $allowBacktrack,
        public readonly ?int $questionPoolSize,
        public readonly bool $countsTowardProgress,
    ) {}

    /** @param array<string, mixed> $overrides */
    public static function for(string $kind, array $overrides = []): self
    {
        $defaults = $kind === Assessment::KIND_TEST
            ? [
                'time_limit_s' => 1800,
                'max_attempts' => 1,
                'pass_percentage' => 40.0,
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'show_answers' => self::SHOW_AFTER_PASS,
                'allow_backtrack' => true,
                'question_pool_size' => null,
                'counts_toward_progress' => true,
            ]
            : [
                'time_limit_s' => null,
                'max_attempts' => null,
                'pass_percentage' => null,
                'shuffle_questions' => false,
                'shuffle_options' => true,
                'show_answers' => self::SHOW_AFTER_SUBMIT,
                'allow_backtrack' => true,
                'question_pool_size' => null,
                'counts_toward_progress' => false,
            ];

        $s = [...$defaults, ...$overrides];

        return new self(
            timeLimitSeconds: $s['time_limit_s'] === null ? null : (int) $s['time_limit_s'],
            maxAttempts: $s['max_attempts'] === null ? null : (int) $s['max_attempts'],
            passPercentage: $s['pass_percentage'] === null ? null : (float) $s['pass_percentage'],
            shuffleQuestions: (bool) $s['shuffle_questions'],
            shuffleOptions: (bool) $s['shuffle_options'],
            showAnswers: (string) $s['show_answers'],
            allowBacktrack: (bool) $s['allow_backtrack'],
            questionPoolSize: $s['question_pool_size'] === null ? null : (int) $s['question_pool_size'],
            countsTowardProgress: (bool) $s['counts_toward_progress'],
        );
    }

    /** Formative assessments have no pass mark, so `passed` stays null. */
    public function isGraded(): bool
    {
        return $this->passPercentage !== null;
    }

    public function mayRevealAnswers(bool $passed): bool
    {
        return match ($this->showAnswers) {
            self::SHOW_AFTER_SUBMIT => true,
            self::SHOW_AFTER_PASS => $passed,
            default => false,
        };
    }
}
