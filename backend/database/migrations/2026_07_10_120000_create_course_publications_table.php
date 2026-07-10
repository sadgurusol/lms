<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable snapshots. Learners read these; authors edit the draft tree.
 *
 * This separation is what buys: continuous authoring with no learner impact,
 * one-UPDATE rollback, offline packs with a stable ETag, and attempts that
 * attribute to the exact content version the learner actually saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->integer('number');
            $table->foreignUuid('schema_version_id')->constrained('schema_versions');
            $table->jsonb('snapshot');
            $table->string('snapshot_etag', 64);
            $table->jsonb('media_manifest')->default(DB::raw("'[]'::jsonb"));
            $table->text('changelog')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->useCurrent();

            $table->unique(['course_id', 'number']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreign('latest_publication_id')
                ->references('id')->on('course_publications')->nullOnDelete();
        });

        // I10: a publication is immutable. Rolling back means repointing
        // courses.latest_publication_id, never editing a snapshot.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_publication_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'course publications are immutable (publication %)', OLD.id
                    USING ERRCODE = 'check_violation';
            END $$;
        SQL);

        // No DELETE trigger: a course cascade-deletes its publications, and a
        // course with learner attempts against it is archived, never deleted.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_publications_immutable
                BEFORE UPDATE ON course_publications
                FOR EACH ROW EXECUTE FUNCTION forbid_publication_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_publications_immutable ON course_publications');
        DB::statement('DROP FUNCTION IF EXISTS forbid_publication_mutation()');

        Schema::table('courses', fn (Blueprint $t) => $t->dropForeign(['latest_publication_id']));
        Schema::dropIfExists('course_publications');
    }
};
