<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The course content tree, and the triggers that make the schema binding real.
 *
 * Invariants enforced here (docs/01-domain-model.md §2):
 *   I1  a course's schema_version_id never changes once nodes exist
 *   I2  root nodes hold root levels, and only root levels
 *   I3  a node's level must be a declared child of its parent's level
 *   I4  a node's level must belong to the course's bound schema version
 *   I5  a block's type must be permitted by its node's level
 *   I7  sibling sort keys are unique
 *   I8  path and depth are derived, never supplied
 *
 * Cardinality (I12) is deliberately *not* enforced here — it is checked at the
 * publish gate. Blocking "a Unit must have >= 1 Lesson" on every save makes it
 * impossible to create an empty Unit and then fill it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCourses();
        $this->createCourseNodes();
        $this->createContentBlocks();
        $this->createTriggers();
    }

    public function down(): void
    {
        foreach ([
            'trg_content_blocks_level' => 'content_blocks',
            'trg_course_nodes_structure' => 'course_nodes',
            'trg_courses_schema_pinned' => 'courses',
        ] as $trigger => $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        foreach ([
            'content_blocks_enforce_level',
            'course_nodes_enforce_structure',
            'courses_pin_schema_version',
        ] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }

        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('course_nodes');
        Schema::dropIfExists('courses');
    }

    private function createCourses(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('code')->nullable()->unique();
            $table->string('subject')->nullable();
            $table->string('grade_band')->nullable();
            $table->string('language', 10)->default('en');

            $table->foreignUuid('schema_version_id')->constrained('schema_versions');

            $table->string('workflow_state')->default('draft');

            // FKs added once course_publications and media exist (M3/M4).
            $table->uuid('latest_publication_id')->nullable();
            $table->uuid('cover_media_id')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('workflow_state');
            $table->index('schema_version_id');
        });

        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_workflow_state_check
            CHECK (workflow_state IN
                ('draft','in_review','changes_requested','approved','published','archived'))");
    }

    private function createCourseNodes(): void
    {
        Schema::create('course_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('schema_level_id')->constrained('schema_levels');

            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();

            $table->string('sort_key');

            // Written by the trigger, never by the application.
            $table->integer('depth')->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('course_nodes', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('course_nodes')->cascadeOnDelete();
            $table->index(['course_id', 'parent_id']);
        });

        DB::statement('ALTER TABLE course_nodes ADD COLUMN path ltree');

        // Sort keys are base-62 fractions compared byte-wise. Under the default
        // locale-aware collation "a" sorts before "B" and the ordering scheme
        // silently collapses. COLLATE "C" is load-bearing, not cosmetic.
        DB::statement('ALTER TABLE course_nodes ALTER COLUMN sort_key TYPE text COLLATE "C"');

        DB::statement(<<<'SQL'
            ALTER TABLE course_nodes ADD COLUMN search_vector tsvector
                GENERATED ALWAYS AS (
                    to_tsvector('english', coalesce(title, '') || ' ' || coalesce(summary, ''))
                ) STORED
        SQL);

        DB::statement('CREATE INDEX course_nodes_path_idx ON course_nodes USING gist (path)');
        DB::statement('CREATE INDEX course_nodes_search_idx ON course_nodes USING gin (search_vector)');

        // I7: unique sibling order. Roots are siblings within the course.
        DB::statement('CREATE UNIQUE INDEX course_nodes_sibling_order_child
            ON course_nodes (parent_id, sort_key) WHERE parent_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX course_nodes_sibling_order_root
            ON course_nodes (course_id, sort_key) WHERE parent_id IS NULL AND deleted_at IS NULL');

        DB::statement('CREATE UNIQUE INDEX course_nodes_sibling_slug
            ON course_nodes (course_id, parent_id, slug) NULLS NOT DISTINCT WHERE deleted_at IS NULL');
    }

    private function createContentBlocks(): void
    {
        Schema::create('content_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_node_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('sort_key');
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->uuid('media_id')->nullable();   // FK added with the media table (M3)
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE content_blocks ALTER COLUMN sort_key TYPE text COLLATE "C"');
        DB::statement('CREATE UNIQUE INDEX content_blocks_sibling_order
            ON content_blocks (course_node_id, sort_key) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX content_blocks_payload_idx
            ON content_blocks USING gin (payload jsonb_path_ops)');
    }

    private function createTriggers(): void
    {
        // I1: rebinding a course to a different schema version would reinterpret
        // every node already authored against the old one.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION courses_pin_schema_version()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.schema_version_id IS DISTINCT FROM OLD.schema_version_id
                   AND EXISTS (SELECT 1 FROM course_nodes WHERE course_id = OLD.id)
                THEN
                    RAISE EXCEPTION
                        'course % already has nodes; its schema version cannot be changed', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_courses_schema_pinned
                BEFORE UPDATE OF schema_version_id ON courses
                FOR EACH ROW EXECUTE FUNCTION courses_pin_schema_version();
        SQL);

        // I2, I3, I4, I8.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION course_nodes_enforce_structure()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                lvl_parent   uuid;
                lvl_version  uuid;
                course_ver   uuid;
                parent_lvl   uuid;
                parent_path  ltree;
                parent_depth int;
                parent_course uuid;
            BEGIN
                SELECT parent_level_id, schema_version_id
                  INTO lvl_parent, lvl_version
                  FROM schema_levels WHERE id = NEW.schema_level_id;

                SELECT schema_version_id INTO course_ver
                  FROM courses WHERE id = NEW.course_id;

                IF lvl_version IS DISTINCT FROM course_ver THEN
                    RAISE EXCEPTION
                        'schema level % does not belong to the schema version bound to course %',
                        NEW.schema_level_id, NEW.course_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.parent_id IS NULL THEN
                    IF lvl_parent IS NOT NULL THEN
                        RAISE EXCEPTION
                            'level % is not a root level and requires a parent node',
                            NEW.schema_level_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    NEW.depth := 0;
                    NEW.path  := text2ltree(replace(NEW.id::text, '-', ''));
                ELSE
                    SELECT schema_level_id, path, depth, course_id
                      INTO parent_lvl, parent_path, parent_depth, parent_course
                      FROM course_nodes WHERE id = NEW.parent_id;

                    IF parent_course IS DISTINCT FROM NEW.course_id THEN
                        RAISE EXCEPTION 'parent node % belongs to a different course', NEW.parent_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF lvl_parent IS NULL THEN
                        RAISE EXCEPTION
                            'level % is a root level and cannot be nested under a node',
                            NEW.schema_level_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF lvl_parent <> parent_lvl THEN
                        RAISE EXCEPTION
                            'level % may not nest under a node of level %', NEW.schema_level_id, parent_lvl
                            USING ERRCODE = 'check_violation';
                    END IF;

                    NEW.depth := parent_depth + 1;
                    NEW.path  := parent_path || text2ltree(replace(NEW.id::text, '-', ''));
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_course_nodes_structure
                BEFORE INSERT OR UPDATE OF parent_id, schema_level_id ON course_nodes
                FOR EACH ROW EXECUTE FUNCTION course_nodes_enforce_structure();
        SQL);

        // I5: a block type must be permitted by the level of the node it sits on.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION content_blocks_enforce_level()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE permitted boolean;
            BEGIN
                SELECT sl.allows_content AND sl.allowed_block_types @> to_jsonb(NEW.type)
                  INTO permitted
                  FROM course_nodes cn
                  JOIN schema_levels sl ON sl.id = cn.schema_level_id
                 WHERE cn.id = NEW.course_node_id;

                IF NOT COALESCE(permitted, false) THEN
                    RAISE EXCEPTION
                        'block type % is not permitted on the level of node %',
                        NEW.type, NEW.course_node_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_content_blocks_level
                BEFORE INSERT OR UPDATE OF type, course_node_id ON content_blocks
                FOR EACH ROW EXECUTE FUNCTION content_blocks_enforce_level();
        SQL);
    }
};
