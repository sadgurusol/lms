<?php

use App\Portal\LessonCounter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A baked lesson count per publication, so the public catalogue can show
 * "N lessons" without loading each course's snapshot. Populated at publish and
 * backfilled here from the stored snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_publications', function (Blueprint $table) {
            $table->unsignedInteger('lessons_count')->default(0)->after('media_manifest');
        });

        // Publications are immutable (a trigger forbids UPDATE). New rows get the
        // count at insert; this one-time backfill of existing rows needs the
        // trigger off. New inserts are unaffected (the trigger is BEFORE UPDATE).
        DB::statement('ALTER TABLE course_publications DISABLE TRIGGER trg_publications_immutable');
        try {
            foreach (DB::table('course_publications')->select('id', 'snapshot')->get() as $row) {
                $snapshot = json_decode((string) $row->snapshot, true) ?: [];
                $count = LessonCounter::count($snapshot['tree'] ?? []);
                DB::table('course_publications')->where('id', $row->id)->update(['lessons_count' => $count]);
            }
        } finally {
            DB::statement('ALTER TABLE course_publications ENABLE TRIGGER trg_publications_immutable');
        }
    }

    public function down(): void
    {
        Schema::table('course_publications', function (Blueprint $table) {
            $table->dropColumn('lessons_count');
        });
    }
};
