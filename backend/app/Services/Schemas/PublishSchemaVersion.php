<?php

namespace App\Services\Schemas;

use App\Models\AuditLog;
use App\Models\SchemaVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Freezes a draft schema version. After this, its levels are immutable and
 * courses may bind to it.
 */
final class PublishSchemaVersion
{
    public function handle(SchemaVersion $version, User $actor): SchemaVersion
    {
        return DB::transaction(function () use ($version, $actor) {
            $version = SchemaVersion::lockForUpdate()->findOrFail($version->id);

            if (! $version->isDraft()) {
                throw new RuntimeException("Schema version {$version->id} is {$version->status}, not draft.");
            }

            $this->assertStructureIsCoherent($version);

            $version->forceFill([
                'status' => SchemaVersion::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $actor->id,
            ])->save();

            AuditLog::record($actor, 'schema_version.published', $version,
                after: ['version' => $version->version],
            );

            return $version;
        });
    }

    /**
     * A schema with no levels describes no course. A schema with no root level
     * describes a course nothing can be added to. Both are publishable only by
     * accident.
     */
    private function assertStructureIsCoherent(SchemaVersion $version): void
    {
        $levels = $version->levels()->get();

        if ($levels->isEmpty()) {
            throw new RuntimeException('A schema version must define at least one level before publishing.');
        }

        if ($levels->whereNull('parent_level_id')->isEmpty()) {
            throw new RuntimeException('A schema version must define at least one root level.');
        }

        $contentBearing = $levels->filter(fn ($level) => $level->allows_content);

        if ($contentBearing->isEmpty()) {
            throw new RuntimeException('A schema version must have at least one level that allows content.');
        }
    }
}
