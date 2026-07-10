<?php

namespace App\Services\Schemas;

use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Edit this published schema" means: clone it into a new draft.
 *
 * The published rows are never touched, so every course already bound to them
 * keeps rendering exactly as authored.
 */
final class CloneSchemaVersion
{
    public function handle(SchemaVersion $source): SchemaVersion
    {
        return DB::transaction(function () use ($source) {
            $schema = $source->courseSchema()->lockForUpdate()->firstOrFail();

            if ($schema->versions()->where('status', SchemaVersion::STATUS_DRAFT)->exists()) {
                throw new RuntimeException(
                    'This schema already has an open draft version. Publish or discard it first.'
                );
            }

            $draft = SchemaVersion::create([
                'course_schema_id' => $schema->id,
                'version' => (int) $schema->versions()->max('version') + 1,
                'status' => SchemaVersion::STATUS_DRAFT,
                'notes' => "Cloned from version {$source->version}",
            ]);

            $this->copyLevels($source, $draft);

            return $draft->refresh();
        });
    }

    /**
     * Levels reference their parent by id, so the tree must be copied
     * parents-first and the old→new ids carried along.
     */
    private function copyLevels(SchemaVersion $source, SchemaVersion $draft): void
    {
        $idMap = [];

        foreach ($source->levels()->orderBy('depth')->get() as $level) {
            $copy = SchemaLevel::create([
                'schema_version_id' => $draft->id,
                'parent_level_id' => $level->parent_level_id === null
                    ? null
                    : $idMap[$level->parent_level_id],
                'name' => $level->name,
                'plural_name' => $level->plural_name,
                'depth' => $level->depth,
                'sort_key' => $level->sort_key,
                'min_occurrences' => $level->min_occurrences,
                'max_occurrences' => $level->max_occurrences,
                'allows_content' => $level->allows_content,
                'allowed_block_types' => $level->allowed_block_types,
                'allows_assessment' => $level->allows_assessment,
                'numbering_style' => $level->numbering_style,
                'label_template' => $level->label_template,
            ]);

            $idMap[$level->id] = $copy->id;
        }
    }
}
