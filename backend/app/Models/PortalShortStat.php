<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Aggregate view count for a portal short (a course's animated step).
 *
 * @property string $course_id
 * @property string $node_id
 * @property int $views
 */
#[Fillable(['course_id', 'node_id', 'views'])]
class PortalShortStat extends Model
{
    use HasUuids;
}
