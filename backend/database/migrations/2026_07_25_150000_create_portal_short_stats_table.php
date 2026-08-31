<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * View counts for portal "shorts" — each short is an animated step of a course
 * (course_id + node_id). Aggregate counter, incremented on impression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_short_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->uuid('node_id');
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_short_stats');
    }
};
