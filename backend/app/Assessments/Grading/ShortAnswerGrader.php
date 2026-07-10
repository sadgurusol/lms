<?php

namespace App\Assessments\Grading;

use App\Models\Question;

/**
 * The asymmetric grader.
 *
 * A match auto-grades correct. A non-match does **not** auto-grade wrong — it
 * returns needsHuman(). "photosynthisis" is a spelling slip, not a wrong answer,
 * and a machine that marks it zero at 2am will be overruled by a teacher at 9am
 * anyway. Better to ask.
 */
final class ShortAnswerGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        $submitted = $this->normalise((string) ($response['text'] ?? ''));

        if ($submitted === '') {
            return GradeResult::incorrect();       // a blank answer is a wrong answer
        }

        $accepted = array_map($this->normalise(...), $question->grading['accept'] ?? []);

        if (in_array($submitted, $accepted, true)) {
            return GradeResult::correct($maxPoints);
        }

        if ($question->grading['fuzzy'] ?? false) {
            $maxDistance = (int) ($question->grading['max_distance'] ?? 2);

            foreach ($accepted as $candidate) {
                if (levenshtein($submitted, $candidate) <= $maxDistance) {
                    return GradeResult::correct($maxPoints);
                }
            }
        }

        return GradeResult::needsHuman();
    }

    /** Casefold, trim, collapse internal whitespace. */
    private function normalise(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }
}
