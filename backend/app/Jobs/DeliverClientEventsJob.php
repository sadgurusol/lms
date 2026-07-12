<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Activity\DeliverClientEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class DeliverClientEventsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $clientId) {}

    /**
     * One in-flight batch per client. Two workers delivering the same stream
     * would post sequences out of order, and order is the contract.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->clientId))->dontRelease()];
    }

    public function handle(DeliverClientEvents $deliverer): void
    {
        $client = Client::find($this->clientId);

        if ($client !== null && $client->isActive()) {
            $deliverer->handle($client);
        }
    }
}
