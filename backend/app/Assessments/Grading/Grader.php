<?php

namespace App\Assessments\Grading;

use App\Models\Question;

interface Grader
{
    /** @param array<string, mixed> $response */
    public function grade(Question $question, array $response, float $maxPoints): GradeResult;
}
