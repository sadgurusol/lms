<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One turn in a tutor conversation.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $role
 * @property string $content
 * @property list<array{id: string, label: string}> $citations
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property Carbon|null $created_at
 */
#[Fillable(['conversation_id', 'role', 'content', 'citations', 'input_tokens', 'output_tokens'])]
class TutorMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TutorConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TutorConversation::class, 'conversation_id');
    }
}
