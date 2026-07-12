<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A learner's progress through one publication of one course.
 *
 * Progress is keyed by publication, not by course: a learner who completed
 * chapter 3 of publication 1 has done so against the content that existed then.
 * Attributing it to publication 2 would silently claim they read text that did
 * not exist when they read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('publication_id')->constrained('course_publications')->cascadeOnDelete();

            // Intentionally NOT a foreign key.
            //
            // Progress must survive an author deleting the node from the draft
            // tree. The node still exists in the frozen snapshot the learner
            // read; the row in course_nodes may not.
            $table->uuid('course_node_id');

            $table->string('state')->default('not_started');
            $table->integer('seconds_spent')->default(0);
            $table->integer('last_position')->nullable();     // video resume point
            $table->timestamp('completed_at')->nullable();

            // The client clock that produced the newest merge, clamped on write.
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'publication_id', 'course_node_id']);
            $table->index(['user_id', 'publication_id']);
        });

        DB::statement("ALTER TABLE node_progress ADD CONSTRAINT node_progress_state_check
            CHECK (state IN ('not_started','in_progress','completed'))");

        DB::statement("ALTER TABLE node_progress ADD CONSTRAINT node_progress_completed_at_check
            CHECK ((state = 'completed') = (completed_at IS NOT NULL))");

        DB::statement('ALTER TABLE node_progress ADD CONSTRAINT node_progress_seconds_check
            CHECK (seconds_spent >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('node_progress');
    }
};
