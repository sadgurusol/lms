<?php

namespace App\Assessments\Grading;

use App\Models\Question;

final class OrderingGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        /** @var list<string> $correct */
        $correct = $question->grading['correct_order'] ?? [];
        /** @var list<string> $given */
        $given = array_values($response['order'] ?? []);

        if ($correct === []) {
            return GradeResult::needsHuman();
        }

        // A submission that omits or invents items is not a partially correct
        // ordering; it is not an ordering at all.
        if (count($given) !== count($correct) || array_diff($given, $correct) !== []) {
            return GradeResult::incorrect();
        }

        if (($question->grading['scoring'] ?? 'exact') === 'exact') {
            return $given === $correct
                ? GradeResult::correct($maxPoints)
                : GradeResult::incorrect();
        }

        return GradeResult::partial($this->kendallTau($given, $correct) * $maxPoints, $maxPoints);
    }

    /**
     * Fraction of item pairs the learner placed in the right relative order.
     *
     * A single item swapped early in a list of ten barely dents the score, which
     * is the whole point of asking for an ordering rather than a sequence of
     * independent choices.
     *
     * @param  list<string>  $given
     * @param  list<string>  $correct
     */
    private function kendallTau(array $given, array $correct): float
    {
        $rank = array_flip($correct);
        $n = count($given);
        $totalPairs = $n * ($n - 1) / 2;

        if ($totalPairs === 0) {
            return 1.0;
        }

        $concordant = 0;

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($rank[$given[$i]] < $rank[$given[$j]]) {
                    $concordant++;
                }
            }
        }

        return $concordant / $totalPairs;
    }
}
