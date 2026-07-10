<?php

namespace App\Assessments\Grading;

use App\Models\Question;

final class TrueFalseGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        if (! array_key_exists('answer', $response)) {
            return GradeResult::incorrect();
        }

        return (bool) $response['answer'] === (bool) ($question->grading['answer'] ?? null)
            ? GradeResult::correct($maxPoints)
            : GradeResult::incorrect();
    }
}
