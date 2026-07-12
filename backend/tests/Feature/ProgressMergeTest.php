<?php

use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\User;
use App\Services\Progress\CourseProgress;
use App\Services\Progress\PublicationNodes;
use App\Services\Progress\RecordProgress;
use App\Services\Publishing\PublishCourse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->publication = CoursePublication::where('course_id', $this->course->id)->firstOrFail();
    $this->learner = User::factory()->create();

    // The published tree is Part One > Chapter One > Topic One. Chapter and
    // Topic carry content; Part does not.
    $tree = $this->publication->snapshot['tree'];
    $this->partId = $tree[0]['id'];
    $this->chapterId = $tree[0]['children'][0]['id'];
    $this->topicId = $tree[0]['children'][0]['children'][0]['id'];
});

function record(array $event): NodeProgress
{
    return app(RecordProgress::class)->handle(test()->learner, test()->publication, $event);
}

/*
|--------------------------------------------------------------------------
| Merge rules
|--------------------------------------------------------------------------
*/

it('records a first progress event', function () {
    $p = record(['node_id' => $this->topicId, 'state' => 'in_progress', 'seconds_spent' => 30]);

    expect($p->state)->toBe(NodeProgress::IN_PROGRESS)
        ->and($p->seconds_spent)->toBe(30)
        ->and($p->completed_at)->toBeNull();
});

it('is idempotent: replaying the same event changes nothing', function () {
    $event = ['node_id' => $this->topicId, 'state' => 'in_progress', 'seconds_spent' => 30];

    record($event);
    record($event);
    record($event);

    expect(NodeProgress::count())->toBe(1)
        ->and(NodeProgress::first()->seconds_spent)->toBe(30);
});

/**
 * Two devices report cumulative totals. Summing would double-count the minutes
 * both of them saw; taking the newer would discard the longer session.
 */
it('settles on the larger seconds total, not the newer one', function () {
    record(['node_id' => $this->topicId, 'seconds_spent' => 300, 'client_updated_at' => now()->subHour()]);
    record(['node_id' => $this->topicId, 'seconds_spent' => 120, 'client_updated_at' => now()]);

    expect(NodeProgress::first()->seconds_spent)->toBe(300);
});

/** A late event from a device that was offline must not un-complete a lesson. */
it('never un-completes a node', function () {
    record(['node_id' => $this->topicId, 'state' => 'completed', 'seconds_spent' => 400]);

    $completedAt = NodeProgress::first()->completed_at;

    record(['node_id' => $this->topicId, 'state' => 'in_progress', 'seconds_spent' => 500]);

    $p = NodeProgress::first();

    expect($p->state)->toBe(NodeProgress::COMPLETED)
        ->and($p->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($p->seconds_spent)->toBe(500);
});

it('never regresses in_progress back to not_started', function () {
    record(['node_id' => $this->topicId, 'state' => 'in_progress']);
    record(['node_id' => $this->topicId, 'state' => 'not_started']);

    expect(NodeProgress::first()->state)->toBe(NodeProgress::IN_PROGRESS);
});

/** A resume point is where the learner most recently was, not the furthest. */
it('takes the last_position from the newest client clock', function () {
    record([
        'node_id' => $this->topicId,
        'last_position' => 400,
        'client_updated_at' => now()->subHour(),
    ]);

    // An older event arriving late must not drag the resume point backwards.
    record([
        'node_id' => $this->topicId,
        'last_position' => 10,
        'client_updated_at' => now()->subDay(),
    ]);

    expect(NodeProgress::first()->last_position)->toBe(400);

    record([
        'node_id' => $this->topicId,
        'last_position' => 55,
        'client_updated_at' => now(),
    ]);

    expect(NodeProgress::first()->last_position)->toBe(55);
});

it('clamps a device clock from the future', function () {
    record(['node_id' => $this->topicId, 'client_updated_at' => now()->addYear()]);

    expect(NodeProgress::first()->client_updated_at->isBefore(now()->addMinutes(6)))->toBeTrue();
});

/**
 * Without clamping, a device claiming next year would win the `>=` comparison
 * against every honest event forever, pinning the resume point permanently.
 *
 * Clamping bounds the damage to the five-minute forward window — it does not
 * erase it. A device five minutes ahead does legitimately hold the newest
 * timestamp for five minutes. That is the trade, and this test states it.
 */
it('bounds a bad clock to the clamp window instead of letting it win forever', function () {
    record(['node_id' => $this->topicId, 'last_position' => 999, 'client_updated_at' => now()->addYear()]);

    // Still inside the clamp window: the bad clock is genuinely the newest.
    $this->travel(1)->minute();
    record(['node_id' => $this->topicId, 'last_position' => 5, 'client_updated_at' => now()]);
    expect(NodeProgress::first()->last_position)->toBe(999);

    // Past it: an honest client takes the resume point back.
    $this->travel(10)->minutes();
    record(['node_id' => $this->topicId, 'last_position' => 5, 'client_updated_at' => now()]);
    expect(NodeProgress::first()->last_position)->toBe(5);
});

it('rejects an unknown progress state', function () {
    expect(fn () => record(['node_id' => $this->topicId, 'state' => 'mastered']))
        ->toThrow(RuntimeException::class, 'Unknown progress state');
});

it('rejects negative seconds', function () {
    record(['node_id' => $this->topicId, 'seconds_spent' => -50]);

    expect(NodeProgress::first()->seconds_spent)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Nodes come from the snapshot, not the draft tree
|--------------------------------------------------------------------------
*/

it('refuses progress against a node that is not in the publication', function () {
    expect(fn () => record(['node_id' => Str::uuid7()->toString()]))
        ->toThrow(RuntimeException::class, 'is not part of this publication');
});

/**
 * `course_node_id` has no foreign key on purpose: the learner is reading a
 * frozen snapshot, and the author may have deleted the node from the draft.
 */
it('keeps progress after the author deletes the node from the draft tree', function () {
    record(['node_id' => $this->topicId, 'state' => 'completed', 'seconds_spent' => 400]);

    $this->course->nodes()->where('id', $this->topicId)->firstOrFail()->forceDelete();

    expect(NodeProgress::count())->toBe(1)
        ->and(NodeProgress::first()->state)->toBe(NodeProgress::COMPLETED);

    // And the node is still in the snapshot the learner is reading.
    expect(app(PublicationNodes::class)->contains($this->publication, $this->topicId))->toBeTrue();
});

it('cascades progress away when the publication is deleted', function () {
    record(['node_id' => $this->topicId]);

    DB::table('courses')->where('id', $this->course->id)->update(['latest_publication_id' => null]);
    DB::table('course_publications')->where('id', $this->publication->id)->delete();

    expect(NodeProgress::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Completion
|--------------------------------------------------------------------------
*/

/** A Part that only groups Chapters is not something a learner completes. */
it('counts only content-bearing nodes toward completion', function () {
    $trackable = app(PublicationNodes::class)->trackable($this->publication);

    expect($trackable)->toHaveCount(2)
        ->and($trackable)->toContain($this->chapterId)
        ->and($trackable)->toContain($this->topicId)
        ->and($trackable)->not->toContain($this->partId);
});

it('reports completion as a percentage of content-bearing nodes', function () {
    $summary = fn () => app(CourseProgress::class)->summarise($this->learner, $this->publication);

    expect($summary()['percent'])->toBe(0.0);

    record(['node_id' => $this->chapterId, 'state' => 'completed', 'seconds_spent' => 60]);
    expect($summary()['percent'])->toBe(50.0);

    record(['node_id' => $this->topicId, 'state' => 'completed', 'seconds_spent' => 240]);

    $done = $summary();
    expect($done['percent'])->toBe(100.0)
        ->and($done['completed_nodes'])->toBe(2)
        ->and($done['total_nodes'])->toBe(2)
        ->and($done['seconds_spent'])->toBe(300);
});

it('does not count an in_progress node as complete', function () {
    record(['node_id' => $this->chapterId, 'state' => 'in_progress']);
    record(['node_id' => $this->topicId, 'state' => 'in_progress']);

    expect(app(CourseProgress::class)->summarise($this->learner, $this->publication)['percent'])->toBe(0.0);
});

/**
 * Progress is keyed by publication. Completing chapter 3 of publication 1 says
 * nothing about publication 2, whose chapter 3 may be different text entirely.
 */
it('keeps progress separate per publication', function () {
    record(['node_id' => $this->topicId, 'state' => 'completed']);

    expect(app(CourseProgress::class)->summarise($this->learner, $this->publication)['percent'])->toBe(50.0);

    app(PublishCourse::class)->handle($this->course->fresh(), User::factory()->create());
    $second = CoursePublication::where('course_id', $this->course->id)->where('number', 2)->firstOrFail();

    expect(app(CourseProgress::class)->summarise($this->learner, $second)['percent'])->toBe(0.0);
});
