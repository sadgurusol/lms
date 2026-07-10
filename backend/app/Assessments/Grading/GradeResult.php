<?php

namespace App\Assessments\Grading;

/**
 * `isCorrect === null` means "a human must decide" — not "wrong".
 *
 * Auto-grading a near-miss spelling as incorrect is the fastest way to lose a
 * teacher's trust in the whole system.
 */
final class GradeResult
{
    private function __construct(
        public readonly ?bool $isCorrect,
        public readonly float $points,
    ) {}

    public static function correct(float $points): self
    {
        return new self(true, $points);
    }

    public static function incorrect(): self
    {
        return new self(false, 0.0);
    }

    /** Never award negative marks on a single item; clamp at zero. */
    public static function partial(float $points, float $maxPoints): self
    {
        $awarded = max(0.0, min($points, $maxPoints));

        return new self($awarded >= $maxPoints, $awarded);
    }

    public static function needsHuman(): self
    {
        return new self(null, 0.0);
    }

    public function needsHumanGrading(): bool
    {
        return $this->isCorrect === null;
    }
}
