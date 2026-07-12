<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Item analysis.
 *
 * Two numbers make the question bank improve itself, and they cost one
 * materialised view:
 *
 *  - **facility** (the p-value): the fraction of learners who got it right.
 *    Near 1.0 the question is too easy; near 0.0 it is broken or miskeyed.
 *
 *  - **discrimination** (corrected point-biserial): how well the item separates
 *    strong learners from weak ones — the correlation between the marks earned
 *    on this item and the learner's score on *the rest of the assessment*.
 *    Below 0.2 it separates nobody. **Below zero, the learners who did best on
 *    the test did worst on this item**, which almost always means the answer key
 *    is wrong.
 *
 *    The subtraction is not cosmetic. Correlating an item against a total that
 *    includes it correlates the item with itself: on a ten-item test that adds a
 *    spurious ~0.3, and on a four-item test it can turn a worthless item into a
 *    good-looking one. Item analysis always uses the rest score.
 *
 * Surface both to authors and miskeyed questions announce themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW question_stats AS
            SELECT aq.question_id,
                   count(*)                                                  AS attempts,
                   avg(aa.points_awarded / NULLIF(aq.points, 0))             AS mean_score,
                   stddev_pop(aa.points_awarded)                             AS score_stddev,
                   avg(CASE WHEN aa.is_correct THEN 1.0 ELSE 0.0 END)        AS facility,
                   -- Corrected item-total correlation: the item against the
                   -- *rest* of the score, never against a total containing it.
                   corr(aa.points_awarded::double precision,
                        (att.score - aa.points_awarded)::double precision)   AS discrimination,
                   max(att.graded_at)                                        AS last_attempt_at
              FROM attempt_answers aa
              JOIN assessment_questions aq ON aq.id = aa.assessment_question_id
              JOIN assessment_attempts  att ON att.id = aa.attempt_id
             WHERE att.state = 'graded'
               AND aa.is_correct IS NOT NULL      -- awaiting a human is not a data point
             GROUP BY aq.question_id
        SQL);

        // REFRESH ... CONCURRENTLY requires a unique index, and without it the
        // refresh takes an ACCESS EXCLUSIVE lock — the stats screen would block
        // every author for the length of the rebuild.
        DB::statement('CREATE UNIQUE INDEX question_stats_question_id_idx ON question_stats (question_id)');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS question_stats');
    }
};
