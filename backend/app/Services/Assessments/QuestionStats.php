<?php

namespace App\Services\Assessments;

use Illuminate\Support\Facades\DB;

/**
 * Reads (and rebuilds) the item-analysis view.
 *
 * The view is a snapshot, not a live query: a grading run over 200k answers
 * should not be recomputed on every page load of the question bank.
 */
final class QuestionStats
{
    /** Below this, an item separates nobody. */
    public const WEAK_DISCRIMINATION = 0.2;

    /** Facility above/below these is a question worth looking at. */
    public const TOO_EASY = 0.95;

    public const TOO_HARD = 0.05;

    public function refresh(): void
    {
        // CONCURRENTLY: authors keep reading the old snapshot while the new one
        // builds. Requires the unique index created with the view.
        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY question_stats');
    }

    /** @return array<string, mixed>|null */
    public function for(string $questionId): ?array
    {
        $row = DB::table('question_stats')->where('question_id', $questionId)->first();

        if ($row === null) {
            return null;
        }

        $discrimination = $row->discrimination === null ? null : (float) $row->discrimination;
        $facility = (float) $row->facility;

        return [
            'question_id' => $row->question_id,
            'attempts' => (int) $row->attempts,
            'facility' => round($facility, 4),
            'discrimination' => $discrimination === null ? null : round($discrimination, 4),
            'mean_score' => round((float) $row->mean_score, 4),
            'flags' => array_values(array_filter([
                $facility >= self::TOO_EASY ? 'too_easy' : null,
                $facility <= self::TOO_HARD ? 'too_hard' : null,
                // The loudest signal in the whole bank: the strongest learners
                // did worst on this item.
                $discrimination !== null && $discrimination < 0 ? 'likely_miskeyed' : null,
                $discrimination !== null && $discrimination >= 0 && $discrimination < self::WEAK_DISCRIMINATION
                    ? 'weak_discrimination' : null,
            ])),
        ];
    }
}
