<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pivot. `points` overrides the question's default for this assessment.
 *
 * @property string $id
 * @property string $assessment_id
 * @property string $question_id
 * @property numeric-string $points
 * @property string $sort_key
 * @property-read Question $question
 */
#[Fillable(['assessment_id', 'question_id', 'points', 'sort_key'])]
class AssessmentQuestion extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
