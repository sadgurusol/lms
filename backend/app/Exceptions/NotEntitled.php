<?php

namespace App\Exceptions;

use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * 403, never 404.
 *
 * A 404 makes "does this exist?" indistinguishable from "may I read it?", and
 * support cannot triage the difference. The `reason` and `cta` are what the
 * Flutter app renders: a B2C learner gets a paywall, a B2B learner gets "ask
 * your school". Showing a paywall for content their school was supposed to buy
 * is a bad day for everyone.
 */
final class NotEntitled extends RuntimeException
{
    public const NO_GRANT = 'no_grant';

    public const NOT_PUBLISHED = 'not_published';

    public const SUBSCRIPTION_EXPIRED = 'subscription_expired';

    public const NO_CLIENT_ENTITLEMENT = 'no_client_entitlement';

    private function __construct(
        public readonly string $reason,
        public readonly string $title,
        /** @var array<string, mixed>|null */
        public readonly ?array $cta,
    ) {
        parent::__construct($title);
    }

    public static function forCourse(Course $course, ?string $clientId = null): self
    {
        if ($course->latest_publication_id === null) {
            return new self(
                self::NOT_PUBLISHED,
                'This course is not available yet.',
                cta: null,
            );
        }

        // With a client context the learner cannot buy their way in; only their
        // institution can. Route them at someone who can actually fix it.
        return $clientId === null
            ? new self(self::NO_GRANT, "You don't have access to this course.", ['kind' => 'paywall'])
            : new self(
                self::NO_CLIENT_ENTITLEMENT,
                "Your school doesn't have access to this course.",
                ['kind' => 'contact_client'],
            );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'type' => 'https://lms.dev/errors/not-entitled',
            'title' => $this->title,
            'status' => 403,
            'reason' => $this->reason,
            'cta' => $this->cta,
        ], fn ($value) => $value !== null), 403, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
