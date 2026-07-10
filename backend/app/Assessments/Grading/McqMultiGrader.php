<?php

namespace App\Assessments\Grading;

use App\Models\Question;

final class McqMultiGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        /** @var list<string> $chosen */
        $chosen = array_values(array_unique($response['option_ids'] ?? []));

        $correct = $question->options->where('is_correct', true)->pluck('id')->all();
        $totalCorrect = count($correct);

        if ($totalCorrect === 0) {
            return GradeResult::needsHuman();      // a miskeyed question, not a wrong answer
        }

        $hits = count(array_intersect($chosen, $correct));
        $misses = count(array_diff($chosen, $correct));

        $partial = ($question->grading['scoring'] ?? 'all_or_nothing') === 'partial';

        if (! $partial) {
            return $hits === $totalCorrect && $misses === 0
                ? GradeResult::correct($maxPoints)
                : GradeResult::incorrect();
        }

        // (correct - incorrect) / total_correct, clamped at zero: selecting
        // everything must not score better than selecting nothing.
        $fraction = ($hits - $misses) / $totalCorrect;

        return GradeResult::partial($fraction * $maxPoints, $maxPoints);
    }
}
