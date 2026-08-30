<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A URL-friendly slug for public course links (the public learning portal). Kept
 * nullable + unique; the Course model stamps one on create and the public routes
 * fall back to the id when a slug is absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('code');
        });

        // Backfill existing courses with a unique slug derived from the title.
        $seen = [];
        foreach (DB::table('courses')->select('id', 'title')->get() as $course) {
            $base = Str::slug((string) $course->title) ?: 'course';
            $slug = $base;
            $n = 1;
            while (isset($seen[$slug]) || DB::table('courses')->where('slug', $slug)->exists()) {
                $slug = $base.'-'.(++$n);
            }
            $seen[$slug] = true;
            DB::table('courses')->where('id', $course->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
