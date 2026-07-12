<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram index on node titles.
 *
 * The tsvector index already handles whole words; pg_trgm handles the typo. A
 * learner searching "kinemtics" should still find Kinematics, and without this
 * index the similarity() filter is a sequential scan of every node in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX course_nodes_title_trgm_idx
            ON course_nodes USING gin (title gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS course_nodes_title_trgm_idx');
    }
};
