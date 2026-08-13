<?php

namespace App\Console\Commands;

use App\Entitlements\EntitlementResolver;
use App\Launch\CustomJwtValidator;
use App\Models\Course;
use App\Services\Launch\ExchangeTicket;
use App\Services\Launch\HandleLaunch;
use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Proves the ischool → LMS custom-JWT launch handshake end to end, in-process
 * (no HTTP server needed): sign a launch token with ischool's private key, run it
 * through the exact validator/handler/exchanger the /v1/launch endpoints use, and
 * confirm a session + deep link + entitlement come out (docs/14 WS4).
 *
 * Prereq: php artisan db:seed --class=IschoolLaunchSeeder
 */
class VerifyIschoolLaunchCommand extends Command
{
    protected $signature = 'lms:verify-ischool-launch {--sub=student-verify-1} {--token= : Verify a launch token signed elsewhere (e.g. by ischool) instead of self-signing}';

    protected $description = 'Verify the ischool custom-JWT launch handshake end to end';

    public function handle(
        CustomJwtValidator $validator,
        HandleLaunch $handler,
        ExchangeTicket $exchanger,
        EntitlementResolver $entitlements,
    ): int {
        $jwt = $this->option('token');
        if ($jwt) {
            $this->info('① Using an externally-signed launch token ('.strlen($jwt).' chars).');
        } else {
            if (! Storage::disk('local')->exists('launch/ischool-private.pem')) {
                $this->error('Missing ischool private key. Run: php artisan db:seed --class=IschoolLaunchSeeder');

                return self::FAILURE;
            }
            $privateKey = Storage::disk('local')->get('launch/ischool-private.pem');

            // 1) ischool signs a short-lived launch token (what its backend would do).
            $now = time();
            $jwt = JWT::encode([
                'iss' => 'ischool',
                'aud' => config('launch.audience'),
                'sub' => (string) $this->option('sub'),
                'jti' => (string) Str::uuid(),
                'iat' => $now,
                'exp' => $now + 60,
                'nonce' => Str::random(24),
                'name' => 'Verify Student',
                'role' => 'learner',
                'context' => ['id' => 'demo-class', 'title' => 'Demo Class', 'type' => 'class'],
                'resource' => ['course_code' => 'ANIM-DEMO-01'],
            ], $privateKey, 'RS256', 'ischool-2026');
            $this->info('① Signed launch token ('.strlen($jwt).' chars).');
        }

        try {
            // 2) LMS validates + 3) mints a session/ticket + 4) app exchanges it.
            $req = $validator->validate($jwt);
            $this->info('② Validated: client=ischool, sub='.$req->externalUserId.', role='.$req->role.', course_code='.$req->courseCode);

            $handled = $handler->handle($req, '127.0.0.1', 'verify-cmd');
            $this->info('③ Launch session '.$handled['session']->id.' + ticket minted (60s).');

            $exchange = $exchanger->handle($handled['ticket']);
        } catch (\Throwable $e) {
            $this->error('Handshake failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $session = $exchange['session'];
        $user = $exchange['token']->accessToken->tokenable;
        $course = Course::find($session->resourceLink?->course_id);

        $this->info('④ Ticket exchanged → access token for launched user.');
        $this->line('    client_id        : '.$session->client_id);
        $this->line('    launch_session_id: '.$session->id);
        $this->line('    deep_link course : '.($course?->title ?? '—').' ('.$session->resourceLink?->course_id.')');
        $this->line('    published        : '.($course?->latest_publication_id ? 'yes' : 'no'));

        $entitled = $course !== null && $entitlements->entitles($user, $course, $session->client_id);
        $this->line('    entitlement      : '.($entitled ? 'GRANTED ✅' : 'NOT granted ❌'));

        return $entitled ? self::SUCCESS : self::FAILURE;
    }
}
