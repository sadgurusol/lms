<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');

            // Nullable: client-provisioned users (LTI/JWT launch) have no email and
            // no password identity, so they cannot log in directly or be phished
            // into it. See docs/10-clients-and-launch.md §7.
            $table->string('email')->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();

            $table->string('status')->default('invited');
            $table->string('kind')->default('local');
            $table->string('locale', 10)->default('en');
            $table->date('date_of_birth')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check
            CHECK (status IN ('invited','active','suspended'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_kind_check
            CHECK (kind IN ('local','client_provisioned'))");

        // Partial unique: many client-provisioned users share a NULL email.
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL');

        // A client-provisioned user must never carry a password or an email.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_provisioned_has_no_password
            CHECK (kind <> 'client_provisioned' OR (password IS NULL AND email IS NULL))");

        // One human, many ways to sign in. Never link a launch to a password
        // identity by matching email — that is an account-takeover primitive.
        Schema::create('user_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');       // 'password' | 'google' | 'client:{slug}'
            $table->string('provider_uid');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_uid']);
            $table->index('user_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('users');
    }
};
