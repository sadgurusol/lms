<?php

namespace App\Assessments\Grading;

use App\Models\Question;

/** Always a human. There is no machine here, and pretending otherwise is worse. */
final class EssayGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        return GradeResult::needsHuman();
    }
}
