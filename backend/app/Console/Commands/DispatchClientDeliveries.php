<?php

namespace App\Console\Commands;

use App\Jobs\DeliverClientEventsJob;
use App\Models\Client;
use App\Models\ClientEventOutbox;
use Illuminate\Console\Command;

/**
 * Wakes every client stream that has work and is not parked.
 *
 * One job per client, and `WithoutOverlapping` keeps a single batch in flight —
 * two workers on one stream would post sequences out of order, and order is the
 * contract.
 */
class DispatchClientDeliveries extends Command
{
    protected $signature = 'activity:deliver';

    protected $description = 'Dispatch pending activity deliveries to each client';

    public function handle(): int
    {
        $clientIds = ClientEventOutbox::query()
            ->whereNull('delivered_at')
            ->distinct()
            ->pluck('client_id');

        $dispatched = 0;

        foreach (Client::whereIn('id', $clientIds)->where('status', Client::ACTIVE)->get() as $client) {
            DeliverClientEventsJob::dispatch($client->id);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} client delivery job(s).");

        return self::SUCCESS;
    }
}
