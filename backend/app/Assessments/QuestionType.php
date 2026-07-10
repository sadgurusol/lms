<?php

namespace App\Assessments;

enum QuestionType: string
{
    case McqSingle = 'mcq_single';
    case McqMulti = 'mcq_multi';
    case TrueFalse = 'true_false';
    case Numeric = 'numeric';
    case FillBlank = 'fill_blank';
    case Match = 'match';
    case Ordering = 'ordering';
    case ShortAnswer = 'short_answer';
    case Essay = 'essay';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** Types whose correct answer lives in question_options rather than `grading`. */
    public function usesOptions(): bool
    {
        return match ($this) {
            self::McqSingle, self::McqMulti, self::TrueFalse, self::Match, self::Ordering => true,
            default => false,
        };
    }

    /**
     * An essay always needs a human. A short answer *may*: a near-miss spelling
     * is a human's call, so the grader returns null rather than marking it wrong.
     */
    public function alwaysNeedsHuman(): bool
    {
        return $this === self::Essay;
    }
}
