<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Media assets, uploaded direct-to-bucket and transcoded by a provider.
 *
 * Bytes never pass through php-fpm. The app hands out a presigned URL, the
 * client uploads to S3, and a webhook tells us when the transcode is ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk')->default('s3');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime');
            $table->bigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('kind');

            $table->string('provider')->nullable();          // 'mux' | 'cloudflare'
            $table->string('provider_asset_id')->nullable();
            $table->string('playback_id')->nullable();
            $table->integer('duration_s')->nullable();

            $table->string('status')->default('uploading');
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('checksum_sha256');    // dedupe: authors re-upload the same diagram constantly
            $table->index('status');
        });

        DB::statement("ALTER TABLE media ADD CONSTRAINT media_kind_check
            CHECK (kind IN ('image','video','document','audio'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_status_check
            CHECK (status IN ('uploading','processing','ready','failed'))");

        // A ready asset must have bytes behind it.
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_ready_has_checksum
            CHECK (status <> 'ready' OR (checksum_sha256 IS NOT NULL AND size_bytes IS NOT NULL))");

        DB::statement('CREATE UNIQUE INDEX media_provider_asset_unique
            ON media (provider, provider_asset_id) WHERE provider_asset_id IS NOT NULL');

        Schema::table('content_blocks', function (Blueprint $table) {
            $table->foreign('media_id')->references('id')->on('media')->nullOnDelete();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreign('cover_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', fn (Blueprint $t) => $t->dropForeign(['cover_media_id']));
        Schema::table('content_blocks', fn (Blueprint $t) => $t->dropForeign(['media_id']));
        Schema::dropIfExists('media');
    }
};
