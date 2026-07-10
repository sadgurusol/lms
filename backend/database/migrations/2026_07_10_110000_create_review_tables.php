<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course grants (the *scope* axis of authorization) and the review workflow.
 *
 * A global role says what kind of thing you may do; a grant says on which
 * courses. Without the grant table you cannot answer "author of *which* course?"
 * and you cannot express the rule that matters most: a reviewer may not review
 * a course they author.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'role']);
            $table->index(['course_id', 'role']);
        });

        DB::statement("ALTER TABLE course_grants ADD CONSTRAINT course_grants_role_check
            CHECK (role IN ('owner','author','reviewer'))");

        Schema::create('review_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state')->default('open');
            $table->text('note')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'state']);
        });

        DB::statement("ALTER TABLE review_requests ADD CONSTRAINT review_requests_state_check
            CHECK (state IN ('open','approved','changes_requested','withdrawn'))");

        // Guardrail against a double submission race: two open reviews on one
        // course have no coherent meaning.
        DB::statement("CREATE UNIQUE INDEX one_open_review_per_course
            ON review_requests (course_id) WHERE state = 'open'");

        Schema::create('review_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_request_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_comment_id')->nullable();
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->string('anchor_type');
            $table->uuid('anchor_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['review_request_id', 'anchor_type', 'anchor_id']);
        });

        Schema::table('review_comments', function (Blueprint $table) {
            $table->foreign('parent_comment_id')->references('id')->on('review_comments')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE review_comments ADD CONSTRAINT review_comments_anchor_type_check
            CHECK (anchor_type IN ('course','node','block'))");

        // A course-level comment anchors to nothing; anything else must anchor.
        DB::statement("ALTER TABLE review_comments ADD CONSTRAINT review_comments_anchor_id_check
            CHECK ((anchor_type = 'course') = (anchor_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comments');
        Schema::dropIfExists('review_requests');
        Schema::dropIfExists('course_grants');
    }
};
