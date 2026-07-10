<?php

use App\Models\AuditLog;
use App\Models\User;

it('records an administrative act into the partitioned table', function () {
    $actor = User::factory()->create();
    $subject = User::factory()->create(['name' => 'Before']);

    AuditLog::record($actor, 'user.suspended', $subject,
        before: ['status' => 'active'],
        after: ['status' => 'suspended'],
    );

    $log = AuditLog::query()->firstOrFail();

    expect($log->action)->toBe('user.suspended')
        ->and($log->actor_id)->toBe($actor->id)
        ->and($log->subject_id)->toBe($subject->id)
        ->and($log->before)->toBe(['status' => 'active'])
        ->and($log->after)->toBe(['status' => 'suspended'])
        ->and($log->created_at)->not->toBeNull();
});

it('routes rows into the partition for their month', function () {
    AuditLog::record(null, 'system.boot', User::factory()->create());

    $partition = 'audit_logs_'.now()->format('Y_m');
    $count = DB::table($partition)->count();

    expect($count)->toBe(1);
});

it('survives an actor being deleted', function () {
    $actor = User::factory()->create();
    AuditLog::record($actor, 'course.published', User::factory()->create());

    $actor->forceDelete();

    // The act happened. Losing the actor must not lose the record.
    expect(AuditLog::query()->firstOrFail()->actor_id)->toBeNull()
        ->and(AuditLog::count())->toBe(1);
});
