<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\ClientUser;

/**
 * Same events, different envelopes, chosen by `clients.settings.report_format`.
 *
 * Privacy rule, for every format: the client re-identifies from their own
 * system. We echo back `external_user_id` — the pseudonymous key they gave us —
 * and never a name or an email we were never supposed to hold.
 */
final class ActivitySerialiser
{
    public const NATIVE = 'native';

    public const XAPI = 'xapi';

    /** @return array<string, mixed> */
    public function forClient(Client $client, ActivityEvent $event): array
    {
        $externalUserId = ClientUser::whereKey($event->client_user_id)->value('external_user_id');

        return match ($client->settings['report_format'] ?? self::NATIVE) {
            self::XAPI => $this->xapi($client, $event, (string) $externalUserId),
            default => $this->native($event, (string) $externalUserId),
        };
    }

    /** @return array<string, mixed> */
    private function native(ActivityEvent $event, string $externalUserId): array
    {
        return [
            'id' => $event->id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'verb' => $event->verb,
            'external_user_id' => $externalUserId,
            'course_id' => $event->course_id,
            'publication_id' => $event->publication_id,
            'node_id' => $event->course_node_id,
            'assessment_id' => $event->assessment_id,
            'attempt_id' => $event->attempt_id,
            'over_seat' => $event->over_seat,
            'payload' => $event->payload,
        ];
    }

    /**
     * xAPI, for clients with an LRS.
     *
     * `actor.account`, never `mbox`. An email address in a statement is PII we
     * were given for display and are now shipping to a third-party store.
     *
     * @return array<string, mixed>
     */
    private function xapi(Client $client, ActivityEvent $event, string $externalUserId): array
    {
        $verb = $event->verb();
        $base = rtrim(config('app.url'), '/');

        return [
            'id' => $event->id,
            'timestamp' => $event->occurred_at->toIso8601ZuluString(),
            'actor' => [
                'objectType' => 'Agent',
                'account' => [
                    'homePage' => $client->settings['lrs_home_page'] ?? "https://{$client->slug}",
                    'name' => $externalUserId,
                ],
            ],
            'verb' => [
                'id' => $verb->iri(),
                'display' => ['en-US' => $verb->display()],
            ],
            'object' => [
                'objectType' => 'Activity',
                'id' => $event->course_node_id === null
                    ? "{$base}/courses/{$event->course_id}"
                    : "{$base}/courses/{$event->course_id}/nodes/{$event->course_node_id}",
                'definition' => [
                    'type' => 'http://adlnet.gov/expapi/activities/module',
                ],
            ],
            'result' => $this->result($event),
            'context' => [
                'registration' => $event->launch_session_id,
                'contextActivities' => [
                    'parent' => [['id' => "{$base}/courses/{$event->course_id}"]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function result(ActivityEvent $event): ?array
    {
        $payload = $event->payload;

        $result = array_filter([
            'completion' => str_ends_with($event->verb, 'completed') ?: null,
            'duration' => isset($payload['seconds_spent'])
                ? 'PT'.(int) $payload['seconds_spent'].'S'
                : null,
            'score' => isset($payload['score'], $payload['max_score']) ? [
                'raw' => $payload['score'],
                'max' => $payload['max_score'],
            ] : null,
            'success' => $payload['passed'] ?? null,
        ], fn ($value) => $value !== null);

        return $result === [] ? null : $result;
    }
}
