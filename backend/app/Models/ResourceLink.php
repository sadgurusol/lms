<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable object the SIS stores: "Grade 10 English → Chapter 3".
 *
 * @property string $id
 * @property string $client_id
 * @property string|null $client_context_id
 * @property string $external_resource_link_id
 * @property string $course_id
 * @property string|null $course_node_id
 * @property string|null $lineitem_url
 * @property-read Course $course
 */
#[Fillable([
    'client_id', 'client_context_id', 'external_resource_link_id',
    'course_id', 'course_node_id', 'lineitem_url',
])]
class ResourceLink extends Model
{
    use HasUuids;

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
