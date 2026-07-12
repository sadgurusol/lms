<?php

namespace App\Services\Activity;

use App\Activity\Verb;
use App\Entitlements\EntitlementResolver;
use App\Exceptions\InvalidActivityEvent;
use App\Models\ActivityEvent;
use App\Models\ClientUser;
use App\Models\Course;
use App\Models\LaunchSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingests one activity event from a learner's client.
 *
 * `client_id`, `client_user_id`, `launch_session_id` and `grant_source` are
 * stamped **server-side from the session**. A client-supplied `client_id` is
 * ignored, not honoured. That is the whole reporting boundary: an event belongs
 * to the context that produced it, and to no other.
 */
final class RecordActivity
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly FanOutToClient $fanOut,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @return bool whether the event was newly recorded (false = a replay)
     */
    public function handle(User $user, array $event): bool
    {
        $verb = $this->verb($event['verb'] ?? null);
        $course = Course::find($event['course_id'] ?? null);

        if ($course === null) {
            throw new InvalidActivityEvent('Unknown course.');
        }

        // The session's client context, from the access token. Never from the
        // event body — a learner could otherwise file their activity under any
        // school in the system.
        $clientId = $user->currentClientId();
        $grant = $this->resolver->grantFor($user, $course, $clientId);

        if ($grant === null) {
            throw new InvalidActivityEvent('You are not entitled to this course.');
        }

        if ($course->latest_publication_id === null) {
            throw new InvalidActivityEvent('This course has no publication.');
        }

        $membership = $clientId === null ? null : ClientUser::query()
            ->where('client_id', $clientId)
            ->where('user_id', $user->id)
            ->first();

        $session = $this->currentLaunchSession($user);

        return DB::transaction(function () use ($event, $user, $course, $clientId, $membership, $session, $verb, $grant) {
            // Global idempotency. The client generates a UUIDv7 per event and
            // replays its outbox after a crash; replay must be free.
            $fresh = DB::table('activity_event_keys')->insertOrIgnore([
                'id' => $event['id'],
                'first_seen_at' => now(),
            ]);

            if ($fresh === 0) {
                return false;
            }

            $record = ActivityEvent::create([
                'id' => $event['id'],
                'occurred_at' => $this->occurredAt($event['occurred_at'] ?? null),
                'user_id' => $user->id,
                'client_id' => $clientId,
                'client_user_id' => $membership?->id,
                'client_context_id' => $session?->client_context_id,
                'launch_session_id' => $session?->id,
                'verb' => $verb->value,
                'course_id' => $course->id,
                'publication_id' => $course->latest_publication_id,
                'course_node_id' => $event['node_id'] ?? null,
                'assessment_id' => $event['assessment_id'] ?? null,
                'attempt_id' => $event['attempt_id'] ?? null,
                'grant_source' => $grant->source,
                'over_seat' => $this->fanOut->isOverSeat($clientId, $course),
                'payload' => $event['payload'] ?? [],
                'device' => $event['device'] ?? [],
            ]);

            // Only a client-attributed event is ever queued for a client.
            if ($clientId !== null) {
                $this->fanOut->queue($clientId, $record);
            }

            return true;
        });
    }

    private function verb(mixed $verb): Verb
    {
        return Verb::tryFrom((string) $verb)
            ?? throw new InvalidActivityEvent("Unknown verb [{$verb}].");
    }

    /**
     * Phones have wrong clocks. An unclamped device timestamp writes an event
     * into next year — and into a partition that does not exist.
     */
    private function occurredAt(mixed $value): Carbon
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

    private function currentLaunchSession(User $user): ?LaunchSession
    {
        $token = $user->currentAccessToken();
        $sessionId = isset($token->launch_session_id) ? (string) $token->launch_session_id : null;

        return $sessionId === null ? null : LaunchSession::find($sessionId);
    }
}
