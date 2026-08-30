<?php

namespace App\Portal;

/**
 * Top-level course grouping for the portal home (above `subject`). A small,
 * code-owned set — add a value + label here to introduce a new one. `subject`
 * remains the finer grain (e.g. "Physics", or an exam name like "UPSC").
 */
class Category
{
    public const ACADEMIC = 'academic';

    public const PROFESSIONAL = 'professional';

    public const COMPETITIVE = 'competitive';

    /** @var array<string, string> value => display label, in display order. */
    public const LABELS = [
        self::ACADEMIC => 'Academic',
        self::PROFESSIONAL => 'Professional',
        self::COMPETITIVE => 'Competitive Exams',
    ];

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(?string $value): ?string
    {
        return $value === null ? null : (self::LABELS[$value] ?? null);
    }
}
