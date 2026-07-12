<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Launch\CustomJwtValidator;
use App\Services\Launch\ExchangeTicket;
use App\Services\Launch\HandleLaunch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LaunchController extends Controller
{
    /**
     * The custom-JWT launch. The token arrives in a POST body, never a query
     * string: URLs leak through history, logs and `Referer` headers.
     */
    public function launch(
        Request $request,
        CustomJwtValidator $validator,
        HandleLaunch $handler,
    ): RedirectResponse {
        $data = $request->validate(['launch_token' => ['required', 'string']]);

        $launch = $validator->validate($data['launch_token']);
        $result = $handler->handle($launch, $request->ip(), $request->userAgent());

        // Universal/App link. The OS opens the installed app; otherwise the same
        // URL resolves as a web page that exchanges the same ticket. A student
        // on a school Chromebook cannot install an APK.
        return redirect()->away(rtrim(config('launch.web_fallback_url'), '/').'/l/'.$result['ticket']);
    }

    /**
     * The app exchanges the opaque ticket for a token over an authenticated
     * POST. This is why the redirect carries a ticket and not the token itself.
     */
    public function exchange(Request $request, ExchangeTicket $exchanger): JsonResponse
    {
        $data = $request->validate(['ticket' => ['required', 'string']]);

        $result = $exchanger->handle($data['ticket']);
        $session = $result['session'];
        $link = $session->resourceLink;

        return response()->json([
            'access_token' => $result['token']->plainTextToken,
            'expires_in' => 30 * 24 * 3600,
            'launch_context' => [
                'client_id' => $session->client_id,
                'launch_session_id' => $session->id,
                'role' => $session->clientUser->role,
            ],
            'deep_link' => $link === null ? null : [
                'course_id' => $link->course_id,
                'course_node_id' => $link->course_node_id,
            ],
        ]);
    }
}
