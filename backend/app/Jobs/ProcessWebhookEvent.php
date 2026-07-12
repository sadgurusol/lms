<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Billing\ApplyWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $webhookEventId) {}

    public function handle(ApplyWebhookEvent $applier): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        // Already applied by an earlier attempt: at-least-once delivery means
        // this job runs more than once, and it must be safe when it does.
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $applier->handle($event);
    }
}
