<?php

namespace App\Authorization;

/**
 * Global roles — the first of three authorization axes.
 *
 *   Global role  → what kind of thing may you do at all?      (this file)
 *   Course grant → on which courses may you author or review? (course_grants)
 *   Entitlement  → which courses may you read, under whose contract?
 *
 * Reading authority never comes from a role. `LEARNER` permits nothing on its
 * own: attempt.take on an unentitled course is still a 403. See docs/03-rbac.md.
 */
final class Roles
{
    // Staff — the content provider's own people
    public const ADMIN = 'admin';

    public const OPS = 'ops';

    public const CONTENT_AUTHOR = 'content_author';

    public const CONTENT_REVIEWER = 'content_reviewer';

    // Consumers
    public const INSTRUCTOR = 'instructor';

    public const LEARNER = 'learner';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::ADMIN, self::OPS, self::CONTENT_AUTHOR,
            self::CONTENT_REVIEWER, self::INSTRUCTOR, self::LEARNER,
        ];
    }

    /** Roles that must carry TOTP two-factor authentication. */
    public static function requiringTwoFactor(): array
    {
        return [self::ADMIN, self::OPS];
    }
}
