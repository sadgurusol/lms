<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products sit between contracts and courses.
 *
 * Never entitle anyone directly to a course: entitling ABC School to 40 courses
 * is 40 rows, no price, and a support ticket every time you add course 41. A
 * product is the sellable unit — one course, a bundle, or a catalogue.
 *
 * Client entitlements (M9) and subscriptions (M8) both point here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku');
            $table->string('name');
            $table->string('kind');
            $table->string('status')->default('draft');
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
        });

        DB::statement('ALTER TABLE products ALTER COLUMN sku TYPE citext');
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (sku)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_kind_check
            CHECK (kind IN ('course','bundle','catalog'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check
            CHECK (status IN ('draft','active','retired'))");

        Schema::create('product_courses', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();

            // RESTRICT: you may not delete a course somebody paid for. Archive it.
            $table->foreignUuid('course_id')->constrained()->restrictOnDelete();

            $table->timestamp('added_at')->useCurrent();

            $table->primary(['product_id', 'course_id']);
            $table->index('course_id');
        });

        Schema::create('comp_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'product_id']);
        });

        DB::statement("ALTER TABLE comp_grants ADD CONSTRAINT comp_grants_reason_check
            CHECK (reason IN ('staff','reviewer','trial','support'))");
        DB::statement('ALTER TABLE comp_grants ADD CONSTRAINT comp_grants_window_check
            CHECK (ends_at IS NULL OR ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_grants');
        Schema::dropIfExists('product_courses');
        Schema::dropIfExists('products');
    }
};
