<?php

namespace App\Assessments\Grading;

use App\Models\Question;

final class NumericGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        if (! isset($response['answer']) || ! is_numeric($response['answer'])) {
            return GradeResult::incorrect();
        }

        $expected = (float) ($question->grading['answer'] ?? 0);
        $tolerance = abs((float) ($question->grading['tolerance'] ?? 0));

        // 3.14 with tolerance 0.01 accepts [3.13, 3.15]. Never compare floats
        // for equality; a tolerance of 0 still needs an epsilon.
        return abs((float) $response['answer'] - $expected) <= max($tolerance, PHP_FLOAT_EPSILON)
            ? GradeResult::correct($maxPoints)
            : GradeResult::incorrect();
    }
}
