<?php

namespace App\Services\Launch;

use App\Launch\InvalidLaunch;
use App\Launch\LaunchRequest;
use App\Models\Client;
use App\Models\ClientContext;
use App\Models\ClientUser;
use App\Models\Course;
use App\Models\LaunchSession;
use App\Models\LaunchTicket;
use App\Models\ResourceLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a validated launch into a session and a one-time ticket.
 *
 * The ticket, not a token, goes into the redirect: URLs land in browser history,
 * server access logs, `Referer` headers, screen recordings and the clipboard.
 */
final class HandleLaunch
{
    public const TICKET_TTL_SECONDS = 60;

    public function __construct(private readonly ProvisionClientUser $provisioner) {}

    /** @return array{session: LaunchSession, ticket: string} */
    public function handle(LaunchRequest $launch, ?string $ip = null, ?string $userAgent = null): array
    {
        $client = Client::findOrFail($launch->clientId);
        $clientUser = $this->provisioner->handle($client, $launch);

        if (! $clientUser->isActive()) {
            throw InvalidLaunch::because('deactivated', 'This user has been removed from the roster.');
        }

        $context = $this->resolveContext($client, $launch);
        $resourceLink = $this->resolveResourceLink($client, $launch, $context);

        $ticket = Str::random(64);

        $session = DB::transaction(function () use ($client, $clientUser, $context, $resourceLink, $launch, $ip, $userAgent, $ticket) {
            try {
                $session = LaunchSession::create([
                    'client_id' => $client->id,
                    'client_user_id' => $clientUser->id,
                    'resource_link_id' => $resourceLink?->id,
                    'client_context_id' => $context?->id,
                    'message_type' => $launch->messageType,
                    'jti' => $launch->jti,
                    'nonce' => $launch->nonce,
                    'ip' => $ip,
                    'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 500, ''),
                    'expires_at' => now()->addHours(8),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The durable replay guard. Redis may have been flushed; the
                // unique index on (client_id, jti) has not.
                throw InvalidLaunch::because('replayed', 'This launch token has already been used.');
            }

            LaunchTicket::create([
                'token_hash' => LaunchTicket::hash($ticket),
                'launch_session_id' => $session->id,
                'expires_at' => now()->addSeconds(self::TICKET_TTL_SECONDS),
            ]);

            return $session;
        });

        return ['session' => $session, 'ticket' => $ticket];
    }

    private function resolveContext(Client $client, LaunchRequest $launch): ?ClientContext
    {
        if ($launch->externalContextId === null) {
            return null;
        }

        $context = ClientContext::firstOrCreate(
            ['client_id' => $client->id, 'external_context_id' => $launch->externalContextId],
            ['title' => $launch->contextTitle, 'type' => 'class'],
        );

        DB::table('client_context_members')->insertOrIgnore([
            'client_context_id' => $context->id,
            'client_user_id' => ClientUser::where('client_id', $client->id)
                ->where('external_user_id', $launch->externalUserId)->value('id'),
            'role' => $launch->role,
        ]);

        return $context;
    }

    /**
     * A resource link is created on first launch and reused thereafter, so the
     * SIS's own link id stays the durable handle on our content.
     */
    private function resolveResourceLink(Client $client, LaunchRequest $launch, ?ClientContext $context): ?ResourceLink
    {
        if ($launch->externalResourceLinkId === null && $launch->courseCode === null) {
            return null;
        }

        $externalId = $launch->externalResourceLinkId ?? "course:{$launch->courseCode}";

        $existing = ResourceLink::where('client_id', $client->id)
            ->where('external_resource_link_id', $externalId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $course = Course::where('code', $launch->courseCode)->first();

        if ($course === null) {
            throw InvalidLaunch::because('unknown_course', "No course is registered as [{$launch->courseCode}].");
        }

        return ResourceLink::create([
            'client_id' => $client->id,
            'client_context_id' => $context?->id,
            'external_resource_link_id' => $externalId,
            'course_id' => $course->id,
            'course_node_id' => $launch->courseNodeId,
        ]);
    }
}
