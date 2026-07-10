<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CourseSchemaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named blueprint: "Unit → Lesson", "Part → Chapter → Topic".
 *
 * Carries no structure itself — that lives in its versions.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $created_by
 */
#[Fillable(['name', 'slug', 'description', 'created_by'])]
class CourseSchema extends Model
{
    /** @use HasFactory<CourseSchemaFactory> */
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    /** @return HasMany<SchemaVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SchemaVersion::class);
    }

    /** @return HasOne<SchemaVersion, $this> */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(SchemaVersion::class)->where('status', SchemaVersion::STATUS_DRAFT);
    }

    /** @return HasOne<SchemaVersion, $this> */
    public function latestPublishedVersion(): HasOne
    {
        return $this->hasOne(SchemaVersion::class)
            ->where('status', SchemaVersion::STATUS_PUBLISHED)
            ->latestOfMany('version');
    }
}
