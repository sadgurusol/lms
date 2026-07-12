<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every client-facing query filters on the authenticated client.
 *
 * The client id comes from the access token's `cid`, set when a launch ticket
 * was exchanged. **Never** from a route parameter or a request body: that is
 * exactly how one school ends up reading another school's data.
 *
 * Applied to the whole `client` route group, with a test that enumerates the
 * group and fails if any route lacks it. A route added six months from now by
 * someone who has not read this comment is the actual threat.
 */
class EnsureClientScope
{
    public const ATTRIBUTE = 'scoped_client';

    /** The only sanctioned way to read the client a request is scoped to. */
    public static function clientFor(Request $request): Client
    {
        return $request->attributes->get(self::ATTRIBUTE);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $request->user()?->currentClientId();

        if ($clientId === null) {
            abort(403, 'This endpoint requires a client-scoped session.');
        }

        $client = Client::find($clientId);

        if ($client === null || ! $client->isActive()) {
            abort(403, 'This client is not active.');
        }

        // On the request, not as a container instance: a container binding
        // outlives the request that made it, and in a long-lived worker (or a
        // test process) the next request inherits the previous client.
        $request->attributes->set(self::ATTRIBUTE, $client);

        return $next($request);
    }
}
