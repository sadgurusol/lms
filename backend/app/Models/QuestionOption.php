<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $question_id
 * @property array<string, mixed> $body
 * @property bool $is_correct
 * @property string|null $feedback
 * @property string $sort_key
 * @property string|null $match_key
 */
#[Fillable(['question_id', 'body', 'is_correct', 'feedback', 'sort_key', 'match_key'])]
class QuestionOption extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['body' => 'array', 'is_correct' => 'boolean'];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
