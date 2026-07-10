<?php

use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Models\User;
use App\Services\Publishing\PublishCourse;
use App\Services\Schemas\PublishSchemaVersion;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * The "Part → Chapter → Topic" schema from docs/01-domain-model.md §4.
 *
 * Note the Chapter level carries content *and* has children — the bottom level
 * is not the only one that may hold content.
 */
function textbookSchema(): SchemaVersion
{
    $version = SchemaVersion::factory()->create();

    $part = SchemaLevel::factory()->create([
        'schema_version_id' => $version->id,
        'name' => 'Part', 'plural_name' => 'Parts',
        'depth' => 0, 'sort_key' => 'a0',
        'min_occurrences' => 1, 'numbering_style' => 'roman',
        'label_template' => 'Part {n}',
    ]);

    $chapter = SchemaLevel::factory()->under($part)
        ->withContent(['rich_text', 'callout'])
        ->create([
            'name' => 'Chapter', 'plural_name' => 'Chapters',
            'min_occurrences' => 1, 'allows_assessment' => true,
            'label_template' => 'Chapter {n}: {title}',
        ]);

    SchemaLevel::factory()->under($chapter)
        ->withContent(['rich_text', 'video', 'image', 'attachment', 'embed'])
        ->create([
            'name' => 'Topic', 'plural_name' => 'Topics',
            'min_occurrences' => 1, 'max_occurrences' => 40,
            'allows_assessment' => true, 'label_template' => '{n}. {title}',
        ]);

    return $version->refresh();
}

function publish(SchemaVersion $version): SchemaVersion
{
    return app(PublishSchemaVersion::class)
        ->handle($version, User::factory()->create());
}

/** Grant a user a scoped role on a course, e.g. CourseGrant::AUTHOR. */
function grant(User $user, Course $course, string $role): CourseGrant
{
    return CourseGrant::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'role' => $role,
    ]);
}

/** @return array{Course, SchemaLevel, SchemaLevel, SchemaLevel} */
function textbookCourse(): array
{
    $version = publish(textbookSchema());
    $course = Course::factory()->onSchema($version)->create();

    return [
        $course,
        $version->levels()->where('name', 'Part')->firstOrFail(),
        $version->levels()->where('name', 'Chapter')->firstOrFail(),
        $version->levels()->where('name', 'Topic')->firstOrFail(),
    ];
}

/**
 * A textbook course with one full Part/Chapter/Topic branch, published.
 *
 * Attempts reference a publication, so anything learner-facing needs one.
 *
 * @return array{Course, SchemaLevel, SchemaLevel, SchemaLevel}
 */
function publishedTextbookCourse(): array
{
    [$course, $partLevel, $chapterLevel, $topicLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $part = $tree->createNode($course, $partLevel, 'Part One');
    $chapter = $tree->createNode($course, $chapterLevel, 'Chapter One', $part);
    $topic = $tree->createNode($course, $topicLevel, 'Topic One', $chapter);

    foreach ([$chapter, $topic] as $node) {
        ContentBlock::create([
            'course_node_id' => $node->id,
            'type' => 'rich_text',
            'sort_key' => FractionalIndex::between(null, null),
            'payload' => ['format' => 'portable_text', 'body' => []],
        ]);
    }

    app(PublishCourse::class)
        ->handle($course->fresh(), User::factory()->create());

    return [$course->fresh(), $partLevel, $chapterLevel, $topicLevel];
}

/**
 * Assert that a database statement violates a constraint or trigger.
 *
 * The whole point of the structural triggers is that they hold when the
 * application layer is bypassed — seeders, artisan commands, tinker. So the
 * tests bypass Eloquent too, and assert against Postgres directly.
 *
 * The statement runs inside a nested transaction, which Laravel implements as a
 * SAVEPOINT. Postgres aborts a transaction on any error, so without the
 * savepoint the rejection would poison the RefreshDatabase transaction and
 * every assertion after it would fail with "current transaction is aborted".
 */
function expectDatabaseRejection(callable $statement, string $messageFragment): void
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($messageFragment);

        return;
    }

    throw new RuntimeException(
        "Expected the database to reject this statement with '{$messageFragment}', but it succeeded."
    );
}
