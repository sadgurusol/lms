<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public-portal gating hooks (portal plan, Phase 4). Both default to fully open,
 * so nothing changes until an author opts in:
 *   - visibility: public (listed + open) / unlisted (link-only) / private (hidden)
 *   - free_preview_lessons: N free lessons; the rest are stripped from the public
 *     payload and shown behind a wall. NULL = all lessons free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('visibility', 16)->default('public')->after('language');
            $table->unsignedSmallInteger('free_preview_lessons')->nullable()->after('visibility');
        });

        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_visibility_check
            CHECK (visibility IN ('public','unlisted','private'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE courses DROP CONSTRAINT IF EXISTS courses_visibility_check');
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'free_preview_lessons']);
        });
    }
};
