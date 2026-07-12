<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One embedded content node of a publication. See docs/12-ai-tutor.md.
 *
 * @property string $id
 * @property string $publication_id
 * @property string $course_node_id
 * @property int $chunk_index
 * @property string $label
 * @property string $text
 * @property list<float> $embedding
 * @property string|null $model
 */
#[Fillable(['publication_id', 'course_node_id', 'chunk_index', 'label', 'text', 'embedding', 'model'])]
class ContentEmbedding extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }
}
