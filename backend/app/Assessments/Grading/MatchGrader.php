<?php

namespace App\Assessments\Grading;

use App\Models\Question;

/**
 * Two options sharing a `match_key` form a correct pair. The learner submits
 * `{"pairs": {"<left option id>": "<right option id>"}}`.
 *
 * Partial credit per correct pair: getting three of four right and one wrong is
 * not the same as knowing nothing.
 */
final class MatchGrader implements Grader
{
    public function grade(Question $question, array $response, float $maxPoints): GradeResult
    {
        $keyOf = $question->options
            ->filter(fn ($o) => $o->match_key !== null)
            ->pluck('match_key', 'id');

        $totalPairs = $keyOf->unique()->count();

        if ($totalPairs === 0) {
            return GradeResult::needsHuman();       // miskeyed, not wrong
        }

        $seen = [];
        $hits = 0;

        foreach ($response['pairs'] ?? [] as $leftId => $rightId) {
            // A learner may not pair an option with itself, nor reuse one side.
            if ($leftId === $rightId || isset($seen[$leftId]) || isset($seen[$rightId])) {
                continue;
            }

            if (! isset($keyOf[$leftId], $keyOf[$rightId])) {
                continue;
            }

            if ($keyOf[$leftId] === $keyOf[$rightId]) {
                $hits++;
                $seen[$leftId] = $seen[$rightId] = true;
            }
        }

        return GradeResult::partial($hits / $totalPairs * $maxPoints, $maxPoints);
    }
}
