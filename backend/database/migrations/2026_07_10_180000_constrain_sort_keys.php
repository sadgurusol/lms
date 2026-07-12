<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A fractional index is a base-62 fraction with the leading "0." implied. Two
 * facts about it are load-bearing, and until now neither was written down in SQL:
 *
 *  1. Every character must be a base-62 digit, because the column is compared
 *     byte-wise (COLLATE "C") and any other byte breaks the ordering.
 *  2. No key may end in "0". "X0" and "X" denote the same fraction, so there is
 *     no key strictly between "X0" and its successor — an append or an insert
 *     next to such a row has no answer and FractionalIndex::midpoint() throws.
 *
 * Rule 2 is the subtle one. A hand-written "a0" is accepted by every insert and
 * sorts plausibly, and the row sits there harmless until someone appends a
 * sibling after it — at which point the request 500s. That is a long fuse. The
 * factories carried exactly this bug for eleven milestones.
 *
 * See App\Support\FractionalIndex::isValid(), which these constraints mirror.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'schema_levels',
        'course_nodes',
        'content_blocks',
        'question_options',
        'assessment_questions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_sort_key_is_fractional
                CHECK (sort_key ~ '^[0-9A-Za-z]+$' AND sort_key !~ '0$')");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_sort_key_is_fractional");
        }
    }
};
