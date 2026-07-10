<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Must run before every other migration.
 *
 * citext  — case-insensitive email/slug uniqueness without lower() indexes
 * ltree   — course_nodes.path ancestor/descendant queries
 * pgcrypto— gen_random_uuid()
 * pg_trgm — fuzzy title search
 */
return new class extends Migration
{
    private const EXTENSIONS = ['pgcrypto', 'citext', 'ltree', 'pg_trgm'];

    public function up(): void
    {
        foreach (self::EXTENSIONS as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::EXTENSIONS) as $extension) {
            DB::statement("DROP EXTENSION IF EXISTS {$extension}");
        }
    }
};
