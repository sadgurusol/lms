<?php

namespace App\Providers;

use App\Authorization\Roles;
use App\Billing\Gateways\RazorpayGateway;
use App\Billing\PaymentGateway;
use App\Entitlements\EntitlementCache;
use App\Entitlements\EntitlementResolver;
use App\Entitlements\Sources\ClientEntitlementSource;
use App\Entitlements\Sources\CompGrantSource;
use App\Entitlements\Sources\PurchaseSource;
use App\Entitlements\Sources\SubscriptionSource;
use App\Models\User;
use App\Services\Media\LocalTranscodeProvider;
use App\Services\Media\LocalUploadUrlGenerator;
use App\Services\Media\MuxTranscodeProvider;
use App\Services\Media\S3UploadUrlGenerator;
use App\Services\Media\TranscodeProvider;
use App\Services\Media\UploadUrlGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // S3 can presign a direct-to-bucket PUT; a local disk cannot, so dev
        // falls back to a proxy endpoint that writes the bytes for us.
        $this->app->singleton(UploadUrlGenerator::class, function () {
            $disk = config('filesystems.default');

            return config("filesystems.disks.{$disk}.driver") === 's3'
                ? new S3UploadUrlGenerator
                : new LocalUploadUrlGenerator;
        });

        $this->app->singleton(TranscodeProvider::class, fn () => match (config('media.transcoder')) {
            'local' => new LocalTranscodeProvider,
            'mux' => new MuxTranscodeProvider,
            default => throw new InvalidArgumentException(
                'Unsupported transcoder ['.config('media.transcoder').'].'
            ),
        });

        $this->registerEntitlementResolver();
        $this->registerPaymentGateway();
    }

    /**
     * Grant sources, in priority order. The first hit wins.
     *
     * Client entitlements come first so a student launched from ABC School reads
     * under ABC's contract and their activity is reported to ABC — even if they
     * also hold a personal subscription. Attribution follows the session
     * context, not the cheapest grant. Backwards, and ABC's activity report
     * silently omits that student.
     *
     * Below that: a paid subscription outranks a one-time purchase, which
     * outranks a complimentary grant. When two sources cover the same product
     * the learner is attributed to the one they are actually paying for.
     */
    private function registerEntitlementResolver(): void
    {
        $this->app->singleton(
            EntitlementResolver::class,
            fn ($app) => new EntitlementResolver(
                $app->make(EntitlementCache::class),
                [
                    $app->make(ClientEntitlementSource::class),
                    $app->make(SubscriptionSource::class),
                    $app->make(PurchaseSource::class),
                    $app->make(CompGrantSource::class),
                ],
            ),
        );
    }

    private function registerPaymentGateway(): void
    {
        $this->app->singleton(PaymentGateway::class, fn () => match (config('payments.gateway')) {
            'razorpay' => new RazorpayGateway,
            default => throw new InvalidArgumentException(
                'Unsupported payment gateway ['.config('payments.gateway').'].'
            ),
        });
    }

    public function boot(): void
    {
        $this->configureAuthorization();
        $this->configureRateLimits();

        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
    }

    /**
     * Abilities an admin may NOT bypass.
     *
     * Separation of duties is a property of the course, not of the role list.
     * An admin who authored a course must still not be the one who approves it,
     * or the control is theatre. CoursePolicy::review() gets to decide.
     */
    private const NO_ADMIN_BYPASS = ['review'];

    /**
     * Rate limits on the endpoints an attacker actually reaches.
     *
     * `launch` is keyed by IP because the client is unknown until the token
     * verifies — and verifying a signature is exactly the work we do not want to
     * do unboundedly. The webhook endpoints are deliberately unlimited: a
     * provider retrying a burst must not be throttled into a parked stream.
     */
    private function configureRateLimits(): void
    {
        RateLimiter::for('launch', fn (Request $request) => [
            Limit::perMinute(60)->by($request->ip()),
        ]);

        RateLimiter::for('activity', fn (Request $request) => [
            Limit::perMinute(120)->by(self::limitKey($request)),
        ]);

        RateLimiter::for('search', fn (Request $request) => [
            Limit::perMinute(30)->by(self::limitKey($request)),
        ]);

        // The tutor calls a paid model per message; keep a lid on it per learner.
        RateLimiter::for('tutor', fn (Request $request) => [
            Limit::perMinute(15)->by(self::limitKey($request)),
            Limit::perDay(300)->by(self::limitKey($request)),
        ]);

        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by(self::limitKey($request)),
        ]);

        // Credential stuffing is per-IP; a targeted attack on one account is
        // per-email. Limit both, or one of them is the hole.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(10)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }

    /** Per user when we know who they are, per IP when we do not. */
    private static function limitKey(Request $request): string
    {
        $user = $request->user();

        return $user === null ? (string) $request->ip() : (string) $user->getAuthIdentifier();
    }

    private function configureAuthorization(): void
    {
        // Admins bypass every gate but those above.
        //
        // Returning null — not false — is load-bearing: `false` short-circuits
        // the gate as a denial for *non*-admins, silently revoking every
        // permission in the system.
        Gate::before(function (User $user, string $ability) {
            if (in_array($ability, self::NO_ADMIN_BYPASS, true)) {
                return null;
            }

            return $user->hasRole(Roles::ADMIN) ? true : null;
        });
    }
}
