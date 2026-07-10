<?php

namespace App\Assessments\Grading;

use App\Models\Question;

final class FillBlankGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        $blanks = $question->grading['blanks'] ?? [];

        if ($blanks === []) {
            return GradeResult::needsHuman();
        }

        $given = $response['blanks'] ?? [];
        $hits = 0;

        foreach ($blanks as $blank) {
            $answer = trim((string) ($given[$blank['id']] ?? ''));
            $accepted = $blank['accept'] ?? [];

            $matched = ($blank['case_sensitive'] ?? false)
                ? in_array($answer, $accepted, true)
                : in_array(mb_strtolower($answer), array_map(mb_strtolower(...), $accepted), true);

            $hits += $matched ? 1 : 0;
        }

        // Each blank is worth an equal share.
        return GradeResult::partial($hits / count($blanks) * $maxPoints, $maxPoints);
    }
}
