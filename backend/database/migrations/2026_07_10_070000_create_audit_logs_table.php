<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Administrative acts only — who published what, who rotated whose key.
 * Learning acts go to activity_events (docs/12), which has different volume,
 * different consumers and different privacy exposure. Do not merge them.
 *
 * Range-partitioned by month. Laravel's schema builder cannot express
 * PARTITION BY, so this is raw DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id           bigint GENERATED ALWAYS AS IDENTITY,
                actor_id     uuid REFERENCES users(id) ON DELETE SET NULL,
                action       text NOT NULL,
                subject_type text NOT NULL,
                subject_id   uuid,
                before       jsonb,
                after        jsonb,
                ip           inet,
                user_agent   text,
                created_at   timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
        SQL);

        DB::statement('CREATE INDEX audit_logs_subject_idx
            ON audit_logs (subject_type, subject_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_actor_idx
            ON audit_logs (actor_id, created_at DESC)');

        // Bootstrap the current month plus eleven ahead. A scheduled command
        // keeps the runway topped up; a missing partition is a hard INSERT error.
        $month = now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $this->createPartition($month->copy()->addMonths($i));
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_logs CASCADE');
    }

    private function createPartition(Carbon $month): void
    {
        $name = 'audit_logs_'.$month->format('Y_m');
        $from = $month->format('Y-m-d');
        $to = $month->copy()->addMonth()->format('Y-m-d');

        DB::statement("CREATE TABLE IF NOT EXISTS {$name}
            PARTITION OF audit_logs FOR VALUES FROM ('{$from}') TO ('{$to}')");
    }
};
