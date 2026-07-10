<?php

namespace App\Models;

use App\Assessments\AssessmentSettings;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A quiz and a test are the same machinery with different defaults. They differ
 * in timing, attempts and answer visibility — not in structure. Two tables
 * would duplicate attempts, grading and analytics.
 *
 * @property string $id
 * @property string $course_id
 * @property string|null $course_node_id
 * @property string $kind
 * @property string $title
 * @property array<string, mixed>|null $instructions
 * @property array<string, mixed> $settings
 * @property numeric-string $total_points
 * @property-read Collection<int, AssessmentQuestion> $assessmentQuestions
 */
#[Fillable(['course_id', 'course_node_id', 'kind', 'title', 'instructions', 'settings', 'created_by'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const KIND_QUIZ = 'quiz';

    public const KIND_TEST = 'test';

    protected function casts(): array
    {
        return ['instructions' => 'array', 'settings' => 'array'];
    }

    public function config(): AssessmentSettings
    {
        return AssessmentSettings::for($this->kind, $this->settings ?? []);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<AssessmentQuestion, $this> */
    public function assessmentQuestions(): HasMany
    {
        return $this->hasMany(AssessmentQuestion::class)->orderBy('sort_key');
    }

    /** @return HasMany<AssessmentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    /** Recompute the denormalised total from the pivot. */
    public function syncTotalPoints(): void
    {
        $this->forceFill([
            'total_points' => (string) $this->assessmentQuestions()->sum('points'),
        ])->save();
    }
}
