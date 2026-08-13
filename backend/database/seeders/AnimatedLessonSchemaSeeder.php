<?php

namespace Database\Seeders;

use App\Models\CourseSchema;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Models\User;
use App\Services\Schemas\PublishSchemaVersion;
use Illuminate\Database\Seeder;

/**
 * Seeds the reusable "Animated Lesson" course schema — the target shape for
 * ai-platform-authored interactive lessons (WS1/WS2 of docs/14).
 *
 * Node-per-step (docs/14 §5.2b): Chapter → Lesson → Step. The Step level is the
 * deepest content-bearing level and permits the interactive block types
 * (animated_reveal / simulation / animation) alongside rich_text + image. This
 * gives per-step NodeProgress, deep links and navigation for free.
 *
 * Idempotent: re-running is a no-op once the schema exists.
 */
class AnimatedLessonSchemaSeeder extends Seeder
{
    public function run(): void
    {
        if (CourseSchema::where('slug', 'animated-lesson')->exists()) {
            $this->command?->info('Animated Lesson schema already present — skipping.');

            return;
        }

        $admin = User::query()->orderBy('id')->first() ?? User::factory()->create();

        $schema = CourseSchema::create([
            'name' => 'Animated Lesson',
            'slug' => 'animated-lesson',
            'description' => 'Chapter → Lesson → Step. Steps carry AI-authored animated reveals, simulations and animations.',
            'created_by' => $admin->id,
        ]);

        $version = SchemaVersion::create([
            'course_schema_id' => $schema->id,
            'version' => 1,
            'status' => SchemaVersion::STATUS_DRAFT,
        ]);

        $chapter = SchemaLevel::create([
            'schema_version_id' => $version->id,
            'name' => 'Chapter', 'plural_name' => 'Chapters',
            'depth' => 0, 'sort_key' => 'V', 'min_occurrences' => 1,
            'numbering_style' => 'numeric', 'label_template' => 'Chapter {n}: {title}',
            'allows_content' => false, 'allowed_block_types' => [],
            'allows_assessment' => false,
        ]);

        $lesson = SchemaLevel::create([
            'schema_version_id' => $version->id, 'parent_level_id' => $chapter->id,
            'name' => 'Lesson', 'plural_name' => 'Lessons',
            'depth' => 1, 'sort_key' => 'V', 'min_occurrences' => 1,
            'numbering_style' => 'numeric', 'label_template' => 'Lesson {n}: {title}',
            'allows_content' => true, 'allowed_block_types' => ['rich_text', 'callout', 'image'],
            'allows_assessment' => true, // end-of-lesson quiz
        ]);

        SchemaLevel::create([
            'schema_version_id' => $version->id, 'parent_level_id' => $lesson->id,
            'name' => 'Step', 'plural_name' => 'Steps',
            'depth' => 2, 'sort_key' => 'V', 'min_occurrences' => 1,
            'numbering_style' => 'numeric', 'label_template' => 'Step {n}: {title}',
            'allows_content' => true,
            'allowed_block_types' => ['animated_reveal', 'simulation', 'animation', 'image', 'rich_text'],
            'allows_assessment' => false,
        ]);

        app(PublishSchemaVersion::class)->handle($version, $admin);

        $this->command?->info('Animated Lesson schema (Chapter → Lesson → Step) published.');
    }
}
