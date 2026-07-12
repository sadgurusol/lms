<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Learning acts. Administrative acts live in `audit_logs` (docs/12 §1).
 *
 * Every event is attributed to exactly one context: a client, or nobody (B2C).
 * The attribution comes from the session, never from a request parameter. That
 * single fact is what makes "report ABC's activity to ABC, and nothing else"
 * correct by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createDedupeKeys();
        $this->createEvents();
        $this->createOutbox();
        $this->extendClients();
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['report_webhook_url', 'webhook_secret']);
        });

        Schema::dropIfExists('client_event_outbox');
        Schema::dropIfExists('client_outbox_state');
        DB::statement('DROP TABLE IF EXISTS activity_events CASCADE');
        Schema::dropIfExists('activity_event_keys');
    }

    /**
     * Global idempotency for client-generated event ids.
     *
     * Postgres cannot enforce a unique constraint that omits the partition key,
     * so `activity_events` can only be unique on `(id, occurred_at)` — and a
     * replayed event whose `occurred_at` we clamped differently would slip
     * through as a duplicate. This small unpartitioned table carries the real
     * uniqueness. Pruned with the retention window.
     */
    private function createDedupeKeys(): void
    {
        Schema::create('activity_event_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('first_seen_at')->useCurrent();

            $table->index('first_seen_at');
        });
    }

    private function createEvents(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE activity_events (
                id                uuid NOT NULL,
                occurred_at       timestamptz NOT NULL,
                received_at       timestamptz NOT NULL DEFAULT now(),

                user_id           uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,

                -- null => B2C. The privacy partition, and the whole reporting
                -- boundary, is this column.
                client_id         uuid REFERENCES clients(id) ON DELETE CASCADE,
                client_user_id    uuid REFERENCES client_users(id) ON DELETE CASCADE,
                client_context_id uuid REFERENCES client_contexts(id) ON DELETE SET NULL,
                launch_session_id uuid REFERENCES launch_sessions(id) ON DELETE SET NULL,

                verb              text NOT NULL,
                course_id         uuid NOT NULL REFERENCES courses(id),
                publication_id    uuid NOT NULL REFERENCES course_publications(id),
                course_node_id    uuid,
                assessment_id     uuid,
                attempt_id        uuid,

                grant_source      text,
                over_seat         boolean NOT NULL DEFAULT false,
                payload           jsonb NOT NULL DEFAULT '{}'::jsonb,
                device            jsonb NOT NULL DEFAULT '{}'::jsonb,

                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::statement("ALTER TABLE activity_events ADD CONSTRAINT activity_events_grant_source_check
            CHECK (grant_source IS NULL OR grant_source IN ('client','subscription','purchase','grant'))");

        // A client-attributed event must name the membership it came from.
        DB::statement('ALTER TABLE activity_events ADD CONSTRAINT activity_events_client_user_check
            CHECK ((client_id IS NULL) = (client_user_id IS NULL))');

        DB::statement('CREATE INDEX activity_events_client_idx ON activity_events (client_id, occurred_at DESC)');
        DB::statement('CREATE INDEX activity_events_user_idx ON activity_events (user_id, occurred_at DESC)');
        DB::statement('CREATE INDEX activity_events_course_idx ON activity_events (course_id, verb, occurred_at DESC)');

        $month = now()->startOfMonth();
        for ($i = -1; $i < 12; $i++) {
            $this->createPartition($month->copy()->addMonths($i));
        }
    }

    private function createPartition(Carbon $month): void
    {
        $name = 'activity_events_'.$month->format('Y_m');
        $from = $month->format('Y-m-d');
        $to = $month->copy()->addMonth()->format('Y-m-d');

        DB::statement("CREATE TABLE IF NOT EXISTS {$name}
            PARTITION OF activity_events FOR VALUES FROM ('{$from}') TO ('{$to}')");
    }

    private function createOutbox(): void
    {
        /*
         * The per-client sequence counter.
         *
         * A gapless, monotonic sequence is the single feature that makes
         * SIS-side reconciliation possible: they notice `sequence` jumped and
         * ask for the gap. A Postgres sequence would be monotonic but not
         * gapless — a rolled-back transaction burns a number. This row is.
         */
        Schema::create('client_outbox_state', function (Blueprint $table) {
            $table->foreignUuid('client_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('next_sequence')->default(1);
            $table->timestamp('parked_at')->nullable();
            $table->text('parked_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('client_event_outbox', function (Blueprint $table) {
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('sequence');

            // Composite, because activity_events is partitioned on occurred_at.
            $table->uuid('event_id');
            $table->timestamp('event_occurred_at');

            $table->timestamp('delivered_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['client_id', 'sequence']);
        });

        DB::statement('CREATE INDEX client_event_outbox_pending_idx
            ON client_event_outbox (client_id, sequence)
            WHERE delivered_at IS NULL');
    }

    private function extendClients(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('report_webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
        });
    }
};
