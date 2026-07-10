<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Question banks, assessments, and attempts.
 *
 * Questions live in a bank and are linked to assessments through a pivot. The
 * same "identify the tense" question belongs in a topic quiz, the chapter test
 * and next year's revision test; embedding it means fixing a typo three times
 * and item statistics that fragment across duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createBank();
        $this->createAssessments();
        $this->createAttempts();
        $this->createTriggers();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_attempt_answers_belong ON attempt_answers');
        DB::statement('DROP TRIGGER IF EXISTS trg_assessments_level ON assessments');
        DB::statement('DROP FUNCTION IF EXISTS attempt_answers_enforce_assessment()');
        DB::statement('DROP FUNCTION IF EXISTS assessments_enforce_level()');

        Schema::dropIfExists('attempt_answers');
        Schema::dropIfExists('assessment_attempts');
        Schema::dropIfExists('assessment_questions');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_banks');
    }

    private function createBank(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // null = a global bank, curated centrally and reusable across courses.
            $table->foreignUuid('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('question_bank_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('stem');
            $table->jsonb('explanation')->nullable();     // revealed after grading
            $table->decimal('default_points', 6, 2)->default(1);
            $table->string('difficulty')->nullable();
            $table->jsonb('grading')->default(DB::raw("'{}'::jsonb"));   // the answer key
            $table->uuid('media_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['question_bank_id', 'type']);
        });

        DB::statement('ALTER TABLE questions ADD COLUMN tags text[] NOT NULL DEFAULT \'{}\'');
        DB::statement('CREATE INDEX questions_tags_idx ON questions USING gin (tags)');
        DB::statement('ALTER TABLE questions ADD CONSTRAINT questions_media_fk
            FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE SET NULL');

        DB::statement("ALTER TABLE questions ADD CONSTRAINT questions_type_check
            CHECK (type IN ('mcq_single','mcq_multi','true_false','numeric',
                            'short_answer','essay','match','ordering','fill_blank'))");
        DB::statement("ALTER TABLE questions ADD CONSTRAINT questions_difficulty_check
            CHECK (difficulty IS NULL OR difficulty IN ('easy','medium','hard'))");
        DB::statement('ALTER TABLE questions ADD CONSTRAINT questions_points_positive
            CHECK (default_points > 0)');

        Schema::create('question_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('question_id')->constrained()->cascadeOnDelete();
            $table->jsonb('body');
            $table->boolean('is_correct')->default(false);
            $table->text('feedback')->nullable();
            $table->string('sort_key');
            $table->string('match_key')->nullable();   // two options sharing one form a pair

            $table->unique(['question_id', 'sort_key']);
        });

        DB::statement('ALTER TABLE question_options ALTER COLUMN sort_key TYPE text COLLATE "C"');
    }

    private function createAssessments(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('course_node_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('title');
            $table->jsonb('instructions')->nullable();
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->decimal('total_points', 8, 2)->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('course_id');
            $table->index('course_node_id');
        });

        DB::statement("ALTER TABLE assessments ADD CONSTRAINT assessments_kind_check
            CHECK (kind IN ('quiz','test'))");

        Schema::create('assessment_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();

            // RESTRICT: you may not delete a question somebody has answered.
            // Soft-delete it instead; existing assessments keep it, new ones
            // cannot pick it.
            $table->foreignUuid('question_id')->constrained()->restrictOnDelete();

            $table->decimal('points', 6, 2);
            $table->string('sort_key');

            $table->unique(['assessment_id', 'question_id']);
            $table->unique(['assessment_id', 'sort_key']);
        });

        DB::statement('ALTER TABLE assessment_questions ALTER COLUMN sort_key TYPE text COLLATE "C"');
        DB::statement('ALTER TABLE assessment_questions ADD CONSTRAINT assessment_questions_points_positive
            CHECK (points > 0)');
    }

    private function createAttempts(): void
    {
        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();

            // Attempts attribute to the exact content version the learner saw.
            // That is what you need when a student disputes a grade.
            $table->foreignUuid('publication_id')->constrained('course_publications');

            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->integer('attempt_number');
            $table->string('state')->default('in_progress');
            $table->integer('max_index_reached')->default(0);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));

            $table->unique(['assessment_id', 'user_id', 'attempt_number']);
            $table->index(['user_id', 'assessment_id']);
        });

        // The per-attempt question set, frozen at start: shuffles and pools must
        // survive an app restart, and an attempt must be replayable at audit.
        DB::statement('ALTER TABLE assessment_attempts ADD COLUMN question_order uuid[] NOT NULL DEFAULT \'{}\'');

        DB::statement("ALTER TABLE assessment_attempts ADD CONSTRAINT assessment_attempts_state_check
            CHECK (state IN ('in_progress','submitted','awaiting_review','graded','expired'))");
        DB::statement("CREATE INDEX assessment_attempts_in_progress_idx
            ON assessment_attempts (expires_at) WHERE state = 'in_progress'");

        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $table->foreignUuid('assessment_question_id')->constrained()->cascadeOnDelete();
            $table->jsonb('response');
            $table->boolean('is_correct')->nullable();      // null => a human must decide
            $table->decimal('points_awarded', 6, 2)->nullable();
            $table->foreignUuid('grader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('grader_note')->nullable();
            $table->timestamp('answered_at')->useCurrent();

            $table->unique(['attempt_id', 'assessment_question_id']);
        });
    }

    private function createTriggers(): void
    {
        // I6: an assessment may only attach to a node whose level permits one,
        // and that node must belong to the assessment's own course.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assessments_enforce_level()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                allows  boolean;
                node_course uuid;
            BEGIN
                IF NEW.course_node_id IS NULL THEN
                    RETURN NEW;          -- a course-level assessment
                END IF;

                SELECT sl.allows_assessment, cn.course_id
                  INTO allows, node_course
                  FROM course_nodes cn
                  JOIN schema_levels sl ON sl.id = cn.schema_level_id
                 WHERE cn.id = NEW.course_node_id;

                IF node_course IS DISTINCT FROM NEW.course_id THEN
                    RAISE EXCEPTION 'node % belongs to a different course', NEW.course_node_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT COALESCE(allows, false) THEN
                    RAISE EXCEPTION 'the level of node % does not permit assessments', NEW.course_node_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_assessments_level
                BEFORE INSERT OR UPDATE OF course_node_id, course_id ON assessments
                FOR EACH ROW EXECUTE FUNCTION assessments_enforce_level();
        SQL);

        // I13: an answer must reference a question that is in the attempt's own
        // assessment. Two FKs cannot express this; a trigger can.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attempt_answers_enforce_assessment()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                attempt_assessment  uuid;
                question_assessment uuid;
            BEGIN
                SELECT assessment_id INTO attempt_assessment
                  FROM assessment_attempts WHERE id = NEW.attempt_id;

                SELECT assessment_id INTO question_assessment
                  FROM assessment_questions WHERE id = NEW.assessment_question_id;

                IF attempt_assessment IS DISTINCT FROM question_assessment THEN
                    RAISE EXCEPTION
                        'answer references a question outside the attempt''s assessment'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_attempt_answers_belong
                BEFORE INSERT OR UPDATE OF attempt_id, assessment_question_id ON attempt_answers
                FOR EACH ROW EXECUTE FUNCTION attempt_answers_enforce_assessment();
        SQL);
    }
};
