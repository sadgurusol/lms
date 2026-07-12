<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A production-readiness check: reports whether config and integrations are set
 * up to go live. FAIL blocks a safe deploy; WARN flags something suboptimal or a
 * disabled feature. Run it before shipping.
 */
class PreflightCommand extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Check production-readiness of config and integrations';

    private const OK = 'ok';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $prod = app()->environment('production');

        $this->database();
        $this->appConfig($prod);
        $this->infrastructure($prod);
        $this->integrations();

        $this->newLine();
        $this->table(
            ['', 'Check', 'Detail'],
            array_map(fn (array $c) => [$this->icon($c[0]), $c[1], $c[2]], $this->checks),
        );

        $fails = count(array_filter($this->checks, fn ($c) => $c[0] === self::FAIL));
        $warns = count(array_filter($this->checks, fn ($c) => $c[0] === self::WARN));

        $this->newLine();
        if ($fails > 0) {
            $this->error("{$fails} blocking issue(s) and {$warns} warning(s). Not ready to deploy.");

            return self::FAILURE;
        }

        $this->info($warns > 0 ? "Ready, with {$warns} warning(s) to review." : 'All checks passed.');

        return self::SUCCESS;
    }

    private function database(): void
    {
        try {
            DB::connection()->getPdo();
            $this->add(self::OK, 'Database', 'Connected ('.config('database.default').').');
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'Cannot connect: '.$e->getMessage());
        }
    }

    private function appConfig(bool $prod): void
    {
        $this->add(
            config('app.key') ? self::OK : self::FAIL,
            'App key',
            config('app.key') ? 'Set.' : 'APP_KEY is empty — run `php artisan key:generate`.',
        );

        $debug = (bool) config('app.debug');
        $this->add(
            $debug && $prod ? self::FAIL : self::OK,
            'Debug mode',
            $debug ? 'On'.($prod ? ' — must be off in production.' : ' (dev).') : 'Off.',
        );

        $origins = (array) config('cors.allowed_origins');
        $wildcard = in_array('*', $origins, true);
        $this->add(
            $wildcard ? ($prod ? self::FAIL : self::WARN) : self::OK,
            'CORS origins',
            $wildcard ? 'Wildcard "*" — pin CORS_ALLOWED_ORIGINS to your app domains.' : implode(', ', $origins),
        );
    }

    private function infrastructure(bool $prod): void
    {
        $queue = config('queue.default');
        $this->add(
            $queue === 'sync' ? ($prod ? self::FAIL : self::WARN) : self::OK,
            'Queue',
            $queue === 'sync' ? 'sync — jobs run inline; use redis/database + a worker in production.' : $queue,
        );

        $cache = config('cache.default');
        $this->add(
            in_array($cache, ['array', 'file'], true) ? self::WARN : self::OK,
            'Cache',
            (string) $cache,
        );

        $mailer = config('mail.default');
        $this->add(
            in_array($mailer, ['log', 'array'], true) ? self::WARN : self::OK,
            'Mail',
            $mailer === 'log' ? 'log — email is written to the log, not sent.' : (string) $mailer,
        );

        $disk = config('filesystems.default');
        $isLocal = config("filesystems.disks.{$disk}.driver") === 'local';
        $this->add(
            $isLocal ? self::WARN : self::OK,
            'Media storage',
            $isLocal ? "{$disk} (local) — use s3 in production for durable, CDN-served media." : (string) $disk,
        );

        if ($isLocal) {
            $linked = is_link(public_path('storage')) || file_exists(public_path('storage'));
            $this->add($linked ? self::OK : self::WARN, 'Storage link', $linked ? 'Present.' : 'Missing — run `php artisan storage:link`.');
        }
    }

    private function integrations(): void
    {
        // Optional features: a missing key disables the feature, it does not block.
        $this->add(
            filled(config('payments.razorpay.key_id')) && filled(config('payments.razorpay.key_secret')) ? self::OK : self::WARN,
            'Payments (Razorpay)',
            filled(config('payments.razorpay.key_id')) ? 'Configured.' : 'Not configured — B2C checkout is disabled.',
        );

        $this->add(
            filled(config('services.anthropic.key')) ? self::OK : self::WARN,
            'AI tutor (Anthropic)',
            filled(config('services.anthropic.key')) ? 'Configured.' : 'Not configured — the tutor is disabled.',
        );

        $this->add(
            filled(config('services.voyage.key')) ? self::OK : self::WARN,
            'Tutor retrieval (Voyage)',
            filled(config('services.voyage.key')) ? 'Configured.' : 'Not configured — tutor grounds on outline only.',
        );

        if (config('media.transcoder') === 'mux') {
            $this->add(
                filled(config('services.mux.token_id')) ? self::OK : self::FAIL,
                'Video (Mux)',
                filled(config('services.mux.token_id')) ? 'Configured.' : 'Transcoder is mux but MUX_TOKEN_ID is missing.',
            );
        }

        $this->add(
            filled(config('media.webhook_secret')) ? self::OK : self::WARN,
            'Media webhook secret',
            filled(config('media.webhook_secret')) ? 'Set.' : 'Not set — the Mux webhook cannot be verified.',
        );
    }

    private function add(string $status, string $check, string $detail): void
    {
        $this->checks[] = [$status, $check, $detail];
    }

    private function icon(string $status): string
    {
        return match ($status) {
            self::OK => '<info>OK</info>',
            self::WARN => '<comment>WARN</comment>',
            default => '<error>FAIL</error>',
        };
    }
}
