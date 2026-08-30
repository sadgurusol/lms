<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner's enrollment in a public-portal course ("My learning"). Not a paid
 * entitlement — just the list a learner has added or started.
 *
 * @property string $user_id
 * @property string $course_id
 */
#[Fillable(['user_id', 'course_id'])]
class PortalEnrollment extends Model
{
    use HasUuids;

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
