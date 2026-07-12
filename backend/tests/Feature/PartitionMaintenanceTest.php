<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Partitions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The failure mode
|--------------------------------------------------------------------------
*/

/**
 * Postgres does not create partitions on demand. Past the runway, every insert
 * fails at once — audit logging and activity ingest stop dead, silently, a year
 * after launch. This test documents the failure so nobody "fixes" the runway by
 * deleting the maintenance command.
 */
it('hard-fails an insert past the end of the partition runway', function () {
    $actor = User::factory()->create();

    $this->travel(5)->years();

    expectDatabaseRejection(
        fn () => AuditLog::record($actor, 'probe', $actor),
        'no partition of relation "audit_logs" found for row',
    );
});

it('accepts the insert once the runway is extended', function () {
    $actor = User::factory()->create();

    $this->travel(5)->years();

    Partitions::ensure();
    AuditLog::record($actor, 'probe', $actor);

    expect(AuditLog::where('action', 'probe')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Ensuring
|--------------------------------------------------------------------------
*/

it('provisions a runway for every partitioned table', function () {
    $this->artisan('partitions:ensure', ['--months' => 3])->assertSuccessful();

    foreach (array_keys(Partitions::TABLES) as $table) {
        $expected = collect(range(0, 3))
            ->map(fn (int $i) => $table.'_'.now()->startOfMonth()->addMonths($i)->format('Y_m'));

        expect(Partitions::partitionsFor($table))->toContain(...$expected->all());
    }
});

it('is idempotent', function () {
    // The migrations already seed twelve months, so ask for eighteen to have
    // anything left to create.
    $first = Partitions::ensure(18);
    $second = Partitions::ensure(18);

    expect($first)->not->toBeEmpty()
        ->and($second)->toBe([]);

    $this->artisan('partitions:ensure')
        ->expectsOutputToContain('already covers')
        ->assertSuccessful();
});

it('creates partitions ahead of a clock that has moved past the seeded runway', function () {
    $this->travel(18)->months();

    $created = Partitions::ensure(1);

    expect($created)->toContain('activity_events_'.now()->format('Y_m'))
        ->and($created)->toContain('activity_events_'.now()->addMonth()->format('Y_m'));
});

/*
|--------------------------------------------------------------------------
| Pruning
|--------------------------------------------------------------------------
*/

it('drops partitions past their retention window', function () {
    // A partition from long ago, and one from last month.
    $old = 'audit_logs_2019_01';
    DB::statement("CREATE TABLE {$old} PARTITION OF audit_logs
        FOR VALUES FROM ('2019-01-01') TO ('2019-02-01')");

    expect(Partitions::partitionsFor('audit_logs'))->toContain($old);

    $dropped = Partitions::prune('audit_logs', before: now()->subMonths(12));

    expect($dropped)->toContain($old)
        ->and(Partitions::partitionsFor('audit_logs'))->not->toContain($old)
        // The current month is untouched.
        ->and(Partitions::partitionsFor('audit_logs'))->toContain('audit_logs_'.now()->format('Y_m'));
});

it('keeps a partition inside the retention window', function () {
    // activity_events is seeded from last month; audit_logs starts this month.
    $recent = 'activity_events_'.now()->subMonth()->format('Y_m');

    expect(Partitions::partitionsFor('activity_events'))->toContain($recent);

    $dropped = Partitions::prune('activity_events', before: now()->subMonths(12));

    expect($dropped)->not->toContain($recent)
        ->and(Partitions::partitionsFor('activity_events'))->toContain($recent);
});

it('prunes idempotency keys that have outlived any offline outbox', function () {
    DB::table('activity_event_keys')->insert([
        ['id' => Str::uuid7()->toString(), 'first_seen_at' => now()->subDays(60)],
        ['id' => Str::uuid7()->toString(), 'first_seen_at' => now()->subDay()],
    ]);

    $this->artisan('activity:prune')->assertSuccessful();

    expect(DB::table('activity_event_keys')->count())->toBe(1);
});

it('changes nothing on a dry run', function () {
    DB::statement("CREATE TABLE audit_logs_2019_01 PARTITION OF audit_logs
        FOR VALUES FROM ('2019-01-01') TO ('2019-02-01')");

    DB::table('activity_event_keys')->insert([
        'id' => Str::uuid7()->toString(), 'first_seen_at' => now()->subYears(2),
    ]);

    $this->artisan('activity:prune', ['--dry-run' => true])->assertSuccessful();

    expect(Partitions::partitionsFor('audit_logs'))->toContain('audit_logs_2019_01')
        ->and(DB::table('activity_event_keys')->count())->toBe(1);
});

/** Retention is a contract term, and it bounds the cost of an erasure request. */
it('reads its retention windows from config', function () {
    expect(config('retention.audit_logs_months'))->toBe(84)
        ->and(config('retention.activity_events_months'))->toBe(24)
        ->and(config('retention.activity_event_keys_days'))->toBe(45);
});
