<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A learner's "My learning" list on the public portal — a lightweight enrollment
 * (distinct from paid entitlements). Created explicitly, or implicitly the first
 * time a signed-in learner opens a lesson.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_enrollments');
    }
};
