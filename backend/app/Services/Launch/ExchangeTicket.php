<?php

namespace App\Services\Launch;

use App\Launch\InvalidLaunch;
use App\Models\LaunchSession;
use App\Models\LaunchTicket;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Burns the ticket, mints a client-scoped access token.
 */
final class ExchangeTicket
{
    /** @return array{session: LaunchSession, token: NewAccessToken} */
    public function handle(string $ticket): array
    {
        return DB::transaction(function () use ($ticket) {
            // lockForUpdate, not find-then-update: a double-click on the redirect
            // would otherwise mint two sessions from one ticket.
            $record = LaunchTicket::query()
                ->where('token_hash', LaunchTicket::hash($ticket))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw InvalidLaunch::because('bad_ticket', 'This launch link has expired or has already been used.');
            }

            $record->forceFill(['used_at' => now()])->save();

            $session = $record->launchSession;
            $session->forceFill(['exchanged_at' => now()])->save();

            $clientUser = $session->clientUser;
            $user = $clientUser->user;

            $token = $user->createToken(
                "launch:{$session->id}",
                ['attempt.take', 'progress.view.own'],
                now()->addDays(30),
            );

            // The client context travels with the token, not with the request.
            $token->accessToken->forceFill([
                'client_id' => $session->client_id,
                'launch_session_id' => $session->id,
            ])->save();

            return ['session' => $session, 'token' => $token];
        });
    }
}
