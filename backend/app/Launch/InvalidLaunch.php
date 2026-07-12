<?php

namespace App\Launch;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A launch we refused, and exactly which claim we refused it on.
 *
 * "The launch doesn't work" is 80% of B2B support, and the answer is almost
 * always a clock skew, a stale `kid`, or an unregistered deployment. The client
 * admin sees this reason; it must be specific enough to act on.
 */
final class InvalidLaunch extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://lms.dev/errors/invalid-launch',
            'title' => $this->getMessage(),
            'status' => 401,
            'reason' => $this->reason,
        ], 401, ['Content-Type' => 'application/problem+json']);
    }
}
