<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI-tutor conversation, scoped to a course and pinned to the publication it
 * began against. See docs/12-ai-tutor.md.
 *
 * @property string $id
 * @property string $user_id
 * @property string $course_id
 * @property string $publication_id
 * @property string|null $client_id
 * @property string|null $title
 */
#[Fillable(['user_id', 'course_id', 'publication_id', 'client_id', 'title'])]
class TutorConversation extends Model
{
    use HasUuids;

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CoursePublication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(CoursePublication::class, 'publication_id');
    }

    /** @return HasMany<TutorMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TutorMessage::class, 'conversation_id');
    }
}
