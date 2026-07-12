# Production hardening & deploy checklist

Run `php artisan app:preflight` first — it reports the readiness of everything
below and exits non-zero on a blocking issue. This doc is the human companion:
what to provision, what to set, and what is already handled.

## Already handled (in code)

- **Scheduler** (`routes/console.php`): `activity:deliver` (every minute),
  `partitions:ensure` (daily — the audit/activity partition runway),
  `activity:prune` (weekly retention), `assessments:refresh-stats` (daily). A
  production host needs the Laravel scheduler cron entry (below).
- **Queues**: webhook processing, client-event delivery, and **tutor embedding
  on publish** (`EmbedPublicationJob`) run on the queue, not the request.
- **Health check**: `GET /up`.
- **API errors** render as JSON; user vs. server faults are distinguished
  (e.g. checkout answers 422/503, never a raw 500).
- **CORS** is env-driven; **media** on a local disk is served through a
  CORS-capable app route in dev, and via the bucket/CDN on S3.

## Infrastructure to provision

- **PostgreSQL** (managed). The app relies on partitioning and triggers — keep
  the `partitions:ensure` schedule running.
- **Redis** — sessions, cache, and queues all point at it.
- **A queue worker** (supervised, e.g. `php artisan queue:work --tries=3`).
  Without it, webhooks, activity delivery, and tutor embeddings never process.
- **The scheduler cron**: `* * * * * php /path/artisan schedule:run`.
- **S3** (or compatible) for media, with `FILESYSTEM_DISK=s3` and **bucket CORS**
  allowing the app origins. Presigned uploads need the S3 driver.
- **Mail** provider (Postmark/Resend/SES) — dev uses `log`.
- **TLS + reverse proxy**. For the tutor's SSE stream, disable proxy buffering
  (nginx: `proxy_buffering off;` — the app already sends `X-Accel-Buffering: no`).

## Environment (see `.env.example`)

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set.
- `CORS_ALLOWED_ORIGINS` pinned to your real app origins (never `*`).
- `QUEUE_CONNECTION=redis` (or database) — **not** `sync`.
- Payments: `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`;
  register the webhook and set each Plan's `provider_ref` to its Razorpay id.
- AI tutor: `ANTHROPIC_API_KEY`; optional `VOYAGE_API_KEY` for retrieval
  (then `php artisan tutor:embed` to backfill existing courses).
- Video: `MEDIA_TRANSCODER=mux`, `MUX_TOKEN_ID`, `MUX_TOKEN_SECRET`,
  `MEDIA_WEBHOOK_SECRET`; register the Mux webhook.

## Deploy steps

1. `composer install --no-dev --optimize-autoloader`
2. `npm ci && npm run build`
3. `php artisan migrate --force`
4. `php artisan config:cache route:cache event:cache`
5. `php artisan storage:link` (only if serving media from the local disk)
6. Start/restart the queue worker and ensure the scheduler cron is installed.
7. `php artisan app:preflight` — resolve any FAIL before taking traffic.

## Still pending (not code)

- Error monitoring (e.g. Sentry) and log aggregation.
- Database backups + restore drill.
- Secrets management for the keys above.
- Razorpay account, webhook registration, and real Plan `provider_ref`s — the
  one thing gating a live B2C launch.
