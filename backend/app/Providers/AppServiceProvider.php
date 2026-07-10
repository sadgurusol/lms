<?php

namespace App\Providers;

use App\Authorization\Roles;
use App\Entitlements\EntitlementCache;
use App\Entitlements\EntitlementResolver;
use App\Entitlements\Sources\CompGrantSource;
use App\Models\User;
use App\Services\Media\LocalTranscodeProvider;
use App\Services\Media\S3UploadUrlGenerator;
use App\Services\Media\TranscodeProvider;
use App\Services\Media\UploadUrlGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UploadUrlGenerator::class, S3UploadUrlGenerator::class);

        $this->app->singleton(TranscodeProvider::class, fn () => match (config('media.transcoder')) {
            'local' => new LocalTranscodeProvider,
            default => throw new InvalidArgumentException(
                'Unsupported transcoder ['.config('media.transcoder').'].'
            ),
        });

        $this->registerEntitlementResolver();
    }

    /**
     * Grant sources, in priority order. The first hit wins.
     *
     * Client entitlements come first so a student launched from ABC School reads
     * under ABC's contract and their activity is reported to ABC — even if they
     * also hold a personal subscription. Attribution follows the session
     * context, not the cheapest grant.
     *
     * Subscriptions (M8) and client contracts (M9) slot in above CompGrantSource
     * when they exist; nothing else changes.
     */
    private function registerEntitlementResolver(): void
    {
        $this->app->singleton(
            EntitlementResolver::class,
            fn ($app) => new EntitlementResolver(
                $app->make(EntitlementCache::class),
                [
                    $app->make(CompGrantSource::class),
                ],
            ),
        );
    }

    public function boot(): void
    {
        $this->configureAuthorization();

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
