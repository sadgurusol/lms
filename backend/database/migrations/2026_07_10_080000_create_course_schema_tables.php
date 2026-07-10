<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course schemas: the versioned blueprint that dictates a course's shape.
 *
 * A course binds to a schema *version*, never to a bare schema. Renaming "Unit"
 * to "Module" on a live schema would otherwise rewrite the meaning of every
 * course already authored against it. Published versions are immutable, and the
 * database — not the application — is what enforces that.
 *
 * See docs/01-domain-model.md §I9 and docs/02-database-schema.md §2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_schemas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE course_schemas ALTER COLUMN slug TYPE citext');

        Schema::create('schema_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_schema_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_schema_id', 'version']);
        });

        DB::statement("ALTER TABLE schema_versions ADD CONSTRAINT schema_versions_status_check
            CHECK (status IN ('draft','published','archived'))");

        // A published version must carry the evidence of its publication.
        DB::statement("ALTER TABLE schema_versions ADD CONSTRAINT schema_versions_published_at_check
            CHECK (status = 'draft' OR published_at IS NOT NULL)");

        // At most one draft per schema: two concurrent drafts have no merge story.
        DB::statement("CREATE UNIQUE INDEX one_draft_per_schema
            ON schema_versions (course_schema_id) WHERE status = 'draft'");

        Schema::create('schema_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schema_version_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_level_id')->nullable();

            $table->string('name');                 // "Lesson"
            $table->string('plural_name');          // "Lessons"
            $table->integer('depth');               // derived, 0-based
            $table->string('sort_key');             // order among sibling levels

            $table->integer('min_occurrences')->default(0);
            $table->integer('max_occurrences')->nullable();

            $table->boolean('allows_content')->default(false);
            $table->jsonb('allowed_block_types')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('allows_assessment')->default(false);

            $table->string('numbering_style')->default('numeric');
            $table->string('label_template')->default('{title}');

            $table->timestamps();
        });

        // Declared separately: Laravel emits `add primary key` *after* the
        // foreign keys of the same create() block, so a self-referencing FK
        // inside the closure has no unique constraint to point at yet.
        Schema::table('schema_levels', function (Blueprint $table) {
            $table->foreign('parent_level_id')->references('id')->on('schema_levels')->cascadeOnDelete();
            $table->index('schema_version_id');
            $table->index('parent_level_id');
        });

        // Same byte-wise collation as course_nodes.sort_key: these are fractional
        // indices, and a locale-aware collation sorts "a" before "B".
        DB::statement('ALTER TABLE schema_levels ALTER COLUMN sort_key TYPE text COLLATE "C"');

        DB::statement('ALTER TABLE schema_levels ADD CONSTRAINT schema_levels_min_occurrences_check
            CHECK (min_occurrences >= 0)');
        DB::statement('ALTER TABLE schema_levels ADD CONSTRAINT schema_levels_max_occurrences_check
            CHECK (max_occurrences IS NULL OR max_occurrences >= min_occurrences)');
        DB::statement("ALTER TABLE schema_levels ADD CONSTRAINT schema_levels_numbering_style_check
            CHECK (numbering_style IN ('none','numeric','roman','alpha'))");
        DB::statement("ALTER TABLE schema_levels ADD CONSTRAINT schema_levels_block_types_is_array
            CHECK (jsonb_typeof(allowed_block_types) = 'array')");

        // A content-bearing level that permits no block types is a level nobody
        // can put anything on. Almost certainly an authoring mistake.
        DB::statement('ALTER TABLE schema_levels ADD CONSTRAINT schema_levels_content_needs_block_types
            CHECK (NOT allows_content OR jsonb_array_length(allowed_block_types) > 0)');

        DB::statement('CREATE UNIQUE INDEX schema_levels_sibling_order
            ON schema_levels (schema_version_id, parent_level_id, sort_key) NULLS NOT DISTINCT');

        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_schema_levels_immutable ON schema_levels');
        DB::statement('DROP TRIGGER IF EXISTS trg_schema_versions_immutable ON schema_versions');
        DB::statement('DROP TRIGGER IF EXISTS trg_schema_versions_no_delete ON schema_versions');
        DB::statement('DROP FUNCTION IF EXISTS forbid_published_schema_level_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS forbid_published_schema_version_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS forbid_published_schema_version_delete()');

        Schema::dropIfExists('schema_levels');
        Schema::dropIfExists('schema_versions');
        Schema::dropIfExists('course_schemas');
    }

    /**
     * Invariant I9: a published schema version is immutable.
     *
     * Enforced in the database because FormRequest validation is bypassed by
     * every seeder, queue job, artisan command and tinker session.
     */
    private function createImmutabilityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_published_schema_level_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_id     uuid := COALESCE(NEW.schema_version_id, OLD.schema_version_id);
                v_status text;
            BEGIN
                SELECT status INTO v_status FROM schema_versions WHERE id = v_id;

                -- The version row is already gone: this is a cascading delete of
                -- the whole version, not an edit of its levels. Let it through.
                IF NOT FOUND THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                IF v_status <> 'draft' THEN
                    RAISE EXCEPTION
                        'schema version % is % and its levels cannot be modified', v_id, v_status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_schema_levels_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON schema_levels
                FOR EACH ROW EXECUTE FUNCTION forbid_published_schema_level_mutation();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_published_schema_version_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'draft' THEN
                    RETURN NEW;               -- drafts are freely editable
                END IF;

                -- Published and archived versions may only move status forward:
                -- published → archived. Nothing else about them may change.
                IF OLD.status = 'published' AND NEW.status = 'archived'
                   AND NEW.course_schema_id = OLD.course_schema_id
                   AND NEW.version          = OLD.version
                   AND NEW.published_at     IS NOT DISTINCT FROM OLD.published_at
                   AND NEW.published_by     IS NOT DISTINCT FROM OLD.published_by
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION
                    'schema version % is % and cannot be modified', OLD.id, OLD.status
                    USING ERRCODE = 'check_violation';
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_schema_versions_immutable
                BEFORE UPDATE ON schema_versions
                FOR EACH ROW EXECUTE FUNCTION forbid_published_schema_version_mutation();
        SQL);

        // Deleting a published version is a mutation too, and it would cascade
        // its levels away behind the level trigger's back. Drafts may be
        // discarded; published versions are archived, never deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_published_schema_version_delete()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'draft' THEN
                    RAISE EXCEPTION
                        'schema version % is % and cannot be deleted; archive it instead',
                        OLD.id, OLD.status
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN OLD;
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_schema_versions_no_delete
                BEFORE DELETE ON schema_versions
                FOR EACH ROW EXECUTE FUNCTION forbid_published_schema_version_delete();
        SQL);
    }
};
