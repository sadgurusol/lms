<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2B clients.
 *
 * Content stays single-tenant — no `client_id` on courses, nodes or blocks. But
 * a client's *people and their activity* are tenanted, and that data is more
 * sensitive than the content ever was. Every table here carries `client_id`;
 * nothing above the authoring line does.
 *
 * The failure mode to fear is not "school A edits school B's course". It is
 * "school A's report contains school B's students".
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createClients();
        $this->createClientUsers();
        $this->createLaunch();
        $this->createEntitlements();
        $this->extendAccessTokens();
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'launch_session_id']);
        });

        Schema::dropIfExists('client_seat_assignments');
        Schema::dropIfExists('client_entitlements');
        Schema::dropIfExists('launch_tickets');
        Schema::dropIfExists('launch_sessions');
        Schema::dropIfExists('resource_links');
        Schema::dropIfExists('client_context_members');
        Schema::dropIfExists('client_contexts');
        Schema::dropIfExists('client_users');
        Schema::dropIfExists('client_deployments');
        Schema::dropIfExists('client_keys');
        Schema::dropIfExists('clients');
    }

    private function createClients(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('pending');
            $table->string('integration')->default('none');
            $table->string('contact_email')->nullable();
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
        });

        DB::statement('ALTER TABLE clients ALTER COLUMN slug TYPE citext');
        DB::statement('CREATE UNIQUE INDEX clients_slug_unique ON clients (slug)');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check
            CHECK (status IN ('pending','active','suspended','terminated'))");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_integration_check
            CHECK (integration IN ('none','lti_1_3','custom_jwt'))");

        Schema::create('client_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('kid');
            $table->string('algorithm');
            $table->text('public_key')->nullable();     // PEM
            $table->string('jwks_url')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('not_before')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'kid']);
        });

        // Asymmetric only. A symmetric algorithm here would make our
        // verification key a signing key: a leaked secret forges any student.
        DB::statement("ALTER TABLE client_keys ADD CONSTRAINT client_keys_algorithm_check
            CHECK (algorithm IN ('RS256','ES256'))");
        DB::statement("ALTER TABLE client_keys ADD CONSTRAINT client_keys_status_check
            CHECK (status IN ('active','rotating','revoked'))");
        DB::statement('ALTER TABLE client_keys ADD CONSTRAINT client_keys_material_check
            CHECK (public_key IS NOT NULL OR jwks_url IS NOT NULL)');

        Schema::create('client_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('issuer');
            $table->string('deployment_id');
            $table->string('platform_client_id');       // our client_id AT the platform
            $table->string('auth_login_url')->nullable();
            $table->string('auth_token_url')->nullable();
            $table->string('jwks_url');
            $table->timestamps();

            $table->unique(['issuer', 'deployment_id', 'platform_client_id']);
        });
    }

    private function createClientUsers(): void
    {
        Schema::create('client_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('external_user_id');

            // RESTRICT: a launched user's LMS account is the anchor for their
            // attempts and progress. Losing it silently loses their work.
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();

            $table->string('role')->default('learner');
            $table->string('external_name')->nullable();
            $table->string('external_email')->nullable();   // informational only
            $table->string('status')->default('active');
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['client_id', 'external_user_id']);
            $table->index('user_id');
            $table->index(['client_id', 'status']);
        });

        DB::statement("ALTER TABLE client_users ADD CONSTRAINT client_users_role_check
            CHECK (role IN ('learner','instructor','client_admin'))");
        DB::statement("ALTER TABLE client_users ADD CONSTRAINT client_users_status_check
            CHECK (status IN ('active','deactivated'))");

        Schema::create('client_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('external_context_id');
            $table->string('title')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'external_context_id']);
        });

        Schema::create('client_context_members', function (Blueprint $table) {
            $table->foreignUuid('client_context_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_user_id')->constrained()->cascadeOnDelete();
            $table->string('role');

            $table->primary(['client_context_id', 'client_user_id']);
        });
    }

    private function createLaunch(): void
    {
        Schema::create('resource_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_context_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_resource_link_id');
            $table->foreignUuid('course_id')->constrained();
            $table->foreignUuid('course_node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lineitem_url')->nullable();     // AGS target (M10)
            $table->timestamps();

            $table->unique(['client_id', 'external_resource_link_id']);
        });

        Schema::create('launch_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('resource_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('client_context_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_type');
            $table->string('jti');
            $table->string('nonce');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('exchanged_at')->nullable();
            $table->timestamp('expires_at');

            // Durable replay defence. Redis is for speed; Postgres is for truth,
            // and it survives a cache flush.
            $table->unique(['client_id', 'jti']);
            $table->index(['client_user_id', 'created_at']);
        });

        Schema::create('launch_tickets', function (Blueprint $table) {
            // sha256 of the ticket. Never the ticket itself: a leaked database
            // backup must not hand anyone a live session.
            $table->string('token_hash')->primary();
            $table->foreignUuid('launch_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
        });
    }

    private function createEntitlements(): void
    {
        Schema::create('client_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('seat_model')->default('active');
            $table->integer('seat_limit')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('active');
            $table->string('contract_ref')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'product_id', 'starts_at']);
            $table->index(['client_id', 'status']);
        });

        DB::statement("ALTER TABLE client_entitlements ADD CONSTRAINT client_entitlements_seat_model_check
            CHECK (seat_model IN ('assigned','active','unlimited'))");
        DB::statement("ALTER TABLE client_entitlements ADD CONSTRAINT client_entitlements_status_check
            CHECK (status IN ('active','suspended','expired'))");
        // `seat_limit > 0` alone is not enough: a NULL seat_limit makes the
        // comparison NULL, and a CHECK constraint treats NULL as satisfied.
        // A limited seat model with no limit would sail straight through.
        DB::statement("ALTER TABLE client_entitlements ADD CONSTRAINT client_entitlements_seat_limit_check
            CHECK (seat_model = 'unlimited' OR (seat_limit IS NOT NULL AND seat_limit > 0))");
        DB::statement('ALTER TABLE client_entitlements ADD CONSTRAINT client_entitlements_window_check
            CHECK (ends_at IS NULL OR ends_at > starts_at)');

        Schema::create('client_seat_assignments', function (Blueprint $table) {
            // A surrogate key, because this pivot has model behaviour (it busts
            // the entitlement cache). Eloquent's update()/delete() target the
            // primary key, and a composite one leaves them pointing at nothing.
            $table->uuid('id')->primary();
            $table->foreignUuid('client_entitlement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('released_at')->nullable();

            $table->unique(['client_entitlement_id', 'client_user_id']);
        });
    }

    /**
     * The access token carries the client context.
     *
     * `cid` must come from the authenticated session, never from a request
     * parameter — that is how one school ends up reading another school's data.
     */
    private function extendAccessTokens(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->index();
            $table->uuid('launch_session_id')->nullable();
        });
    }
};
