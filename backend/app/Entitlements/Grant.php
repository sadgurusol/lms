<?php

namespace App\Entitlements;

use Illuminate\Support\Carbon;

/**
 * Why this user may read this course, right now.
 *
 * Threaded into the request and stamped onto every activity event, so reporting
 * and revenue attribution fall out of the event stream for free (docs/12).
 */
final class Grant
{
    public function __construct(
        public readonly string $source,
        public readonly ?string $clientId = null,
        public readonly ?string $referenceId = null,
        public readonly ?Carbon $expiresAt = null,
    ) {}

    public const SOURCE_CLIENT = 'client';

    public const SOURCE_SUBSCRIPTION = 'subscription';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_COMP = 'grant';

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'client_id' => $this->clientId,
            'reference_id' => $this->referenceId,
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ];
    }
}
