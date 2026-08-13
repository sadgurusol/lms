<?php

namespace App\Console\Commands;

use App\Models\CourseSchema;
use App\Models\SchemaVersion;
use App\Models\User;
use App\Services\Courses\CreateCourse;
use App\Services\Generation\AnimatedLessonGenerator;
use App\Services\Tree\CourseTree;
use Illuminate\Console\Command;

/**
 * Proves the WS2 path (docs/14): generate an interactive, animated lesson via the
 * ai-platform and materialise it as Chapter → Lesson → Step nodes with blocks,
 * against the "animated-lesson" schema (AnimatedLessonSchemaSeeder).
 */
class GenerateAnimatedLessonCommand extends Command
{
    protected $signature = 'lms:generate-animated-lesson
        {topic : The lesson topic, e.g. "The Water Cycle"}
        {--subject=General}
        {--grade=8}';

    protected $description = 'Generate an animated lesson via the ai-platform into a new course';

    public function handle(CreateCourse $createCourse, CourseTree $tree, AnimatedLessonGenerator $generator): int
    {
        $topic = (string) $this->argument('topic');
        $subject = (string) $this->option('subject');
        $grade = (int) $this->option('grade');

        $actor = User::query()->orderBy('id')->first();
        if ($actor === null) {
            $this->error('No user exists to own the course. Seed a user first.');

            return self::FAILURE;
        }

        $schema = CourseSchema::where('slug', 'animated-lesson')->first();
        if ($schema === null) {
            $this->error('Run AnimatedLessonSchemaSeeder first (php artisan db:seed --class=AnimatedLessonSchemaSeeder).');

            return self::FAILURE;
        }

        $version = SchemaVersion::where('course_schema_id', $schema->id)
            ->where('status', SchemaVersion::STATUS_PUBLISHED)
            ->orderByDesc('version')->firstOrFail();

        $chapterLevel = $version->levels()->where('name', 'Chapter')->firstOrFail();
        $lessonLevel = $version->levels()->where('name', 'Lesson')->firstOrFail();

        $course = $createCourse->handle(
            ['title' => "{$subject}: {$topic}", 'subject' => $subject, 'language' => 'en'],
            $version,
            $actor,
        );
        $chapter = $tree->appendNode($course, $chapterLevel, $subject);
        $lesson = $tree->appendNode($course, $lessonLevel, $topic, $chapter);

        $this->info("Generating \"{$topic}\" (grade {$grade}) via the ai-platform — this can take a minute…");

        try {
            $count = $generator->generate($lesson, [
                'topic' => $topic,
                'grade_level' => $grade,
                'subject' => $subject,
            ]);
        } catch (\Throwable $e) {
            $this->error('Generation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $lesson->refresh()->load('children.blocks');
        $this->info("✅ Created {$count} step(s) under lesson \"{$topic}\" (course {$course->id}).");
        foreach ($lesson->children as $step) {
            $types = $step->blocks->pluck('type')->implode(', ');
            $this->line("  • {$step->title}  [{$types}]");
        }

        return self::SUCCESS;
    }
}
