<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A request to generate a course with AI, from a PDF textbook or a topic brief,
 * structured against a chosen schema. Tracks the async run so the studio can
 * show progress and link to the draft it produced. See docs/14-course-generation.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('schema_version_id')->constrained('schema_versions')->cascadeOnDelete();

            // Set once the draft course is built.
            $table->foreignUuid('course_id')->nullable()->constrained('courses')->nullOnDelete();

            $table->string('name');
            $table->string('source_type');            // 'pdf' | 'brief'
            $table->text('brief')->nullable();
            $table->string('pdf_path')->nullable();

            $table->string('status')->default('pending');   // pending|processing|completed|failed
            $table->text('error')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'created_at']);
        });

        DB::statement("ALTER TABLE course_generations ADD CONSTRAINT course_generations_status_check
            CHECK (status IN ('pending','processing','completed','failed'))");
        DB::statement("ALTER TABLE course_generations ADD CONSTRAINT course_generations_source_check
            CHECK (source_type IN ('pdf','brief'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('course_generations');
    }
};
