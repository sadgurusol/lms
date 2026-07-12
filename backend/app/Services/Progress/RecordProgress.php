<?php

namespace App\Services\Progress;

use App\Exceptions\InvalidProgressEvent;
use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Merges a progress event from a possibly-offline client.
 *
 * The merge happens inside one `ON CONFLICT DO UPDATE`, not read-modify-write.
 * Two devices flushing their outbox at the same moment would otherwise race and
 * one would lose its seconds.
 */
final class RecordProgress
{
    public function __construct(private readonly PublicationNodes $nodes) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(User $user, CoursePublication $publication, array $event): NodeProgress
    {
        $nodeId = (string) ($event['node_id'] ?? '');

        // A client may only report progress against a node that exists in the
        // snapshot it is reading. Otherwise `course_node_id` is an unvalidated
        // uuid a caller can write anything into.
        if (! $this->nodes->contains($publication, $nodeId)) {
            throw new InvalidProgressEvent("Node [{$nodeId}] is not part of this publication.");
        }

        $state = $this->state($event['state'] ?? NodeProgress::IN_PROGRESS);
        $clientUpdatedAt = $this->clientClock($event['client_updated_at'] ?? null);

        DB::statement(<<<'SQL'
            INSERT INTO node_progress
                (id, user_id, publication_id, course_node_id, state, seconds_spent,
                 last_position, completed_at, client_updated_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())
            ON CONFLICT (user_id, publication_id, course_node_id) DO UPDATE SET
                -- Two devices watching the same video should settle on the larger
                -- total, not the newer one. The client reports a cumulative total,
                -- so max() cannot double-count what both devices saw.
                seconds_spent = GREATEST(node_progress.seconds_spent, EXCLUDED.seconds_spent),

                -- Completion is monotonic. A late-arriving "in_progress" event
                -- from a device that was offline must not un-complete a lesson
                -- the learner has already finished.
                state = CASE
                    WHEN node_progress.state = 'completed' OR EXCLUDED.state = 'completed' THEN 'completed'
                    WHEN node_progress.state = 'in_progress' OR EXCLUDED.state = 'in_progress' THEN 'in_progress'
                    ELSE 'not_started'
                END,
                completed_at = COALESCE(node_progress.completed_at, EXCLUDED.completed_at),

                -- A resume point is not a maximum: it is wherever the learner
                -- most recently was. Take it from the newest client clock.
                last_position = CASE
                    WHEN EXCLUDED.client_updated_at >= COALESCE(node_progress.client_updated_at, '-infinity'::timestamptz)
                    THEN EXCLUDED.last_position
                    ELSE node_progress.last_position
                END,
                client_updated_at = GREATEST(
                    COALESCE(node_progress.client_updated_at, '-infinity'::timestamptz),
                    EXCLUDED.client_updated_at
                ),
                updated_at = now()
        SQL, [
            (string) Str::uuid7(),
            $user->id,
            $publication->id,
            $nodeId,
            $state,
            max(0, (int) ($event['seconds_spent'] ?? 0)),
            isset($event['last_position']) ? max(0, (int) $event['last_position']) : null,
            $state === NodeProgress::COMPLETED ? $clientUpdatedAt : null,
            $clientUpdatedAt,
        ]);

        return NodeProgress::where('user_id', $user->id)
            ->where('publication_id', $publication->id)
            ->where('course_node_id', $nodeId)
            ->firstOrFail();
    }

    private function state(mixed $state): string
    {
        $allowed = [NodeProgress::NOT_STARTED, NodeProgress::IN_PROGRESS, NodeProgress::COMPLETED];

        if (! in_array($state, $allowed, true)) {
            throw new InvalidProgressEvent("Unknown progress state [{$state}].");
        }

        return $state;
    }

    /**
     * Phones have wrong clocks. An unclamped device timestamp can pin a resume
     * point to next year and win every subsequent merge.
     */
    private function clientClock(mixed $value): Carbon
    {
        if ($value === null) {
            return now();
        }

        $claimed = Carbon::parse($value);
        $earliest = now()->subDays(30);
        $latest = now()->addMinutes(5);

        return $claimed->lessThan($earliest) ? $earliest
            : ($claimed->greaterThan($latest) ? $latest : $claimed);
    }
}
