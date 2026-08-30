<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A top-level category for a course (academic / professional / competitive), used
 * to group the portal home. Nullable — existing courses stay uncategorised until
 * an author tags them. Validated in the app (App\Portal\Category), not the DB, so
 * new categories don't need a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('category', 24)->nullable()->after('subject');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
