<?php

namespace App\Assessments\Grading;

use App\Models\Question;

final class McqSingleGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        $chosen = $response['option_id'] ?? null;

        if ($chosen === null) {
            return GradeResult::incorrect();
        }

        $correct = $question->options->firstWhere('is_correct', true);

        return $correct !== null && $correct->id === $chosen
            ? GradeResult::correct($maxPoints)
            : GradeResult::incorrect();
    }
}
