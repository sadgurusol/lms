<?php

use App\Models\CompGrant;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use App\Services\Publishing\CourseValidator;
use App\Services\Publishing\PublishCourse;
use App\Services\Publishing\SnapshotBuilder;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;

/**
 * "Seed staging with a course large enough to hurt. Every performance problem in
 * this design shows up there and nowhere smaller." — docs/09-roadmap.md
 *
 * These tests do not measure wall time; a CI box's clock is not a benchmark.
 * They count *queries*, which is what actually degrades: an N+1 turns a 150-node
 * course into 150 round trips and a 1,400-node course into an outage.
 */
function countQueries(callable $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $work();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** Build a course of $parts × $chapters × $topics, every content node filled. */
function bigCourse(int $parts, int $chapters, int $topics): Course
{
    [$course, $partLevel, $chapterLevel, $topicLevel] = textbookCourse();
    $tree = app(CourseTree::class);

    $block = fn (string $nodeId) => ContentBlock::create([
        'course_node_id' => $nodeId,
        'type' => 'rich_text',
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);

    $previousPart = null;

    for ($p = 1; $p <= $parts; $p++) {
        $part = $tree->createNode($course, $partLevel, "Part {$p}", null, $previousPart?->id);
        $previousPart = $part;
        $previousChapter = null;

        for ($c = 1; $c <= $chapters; $c++) {
            $chapter = $tree->createNode($course, $chapterLevel, "Chapter {$p}.{$c}", $part, $previousChapter?->id);
            $previousChapter = $chapter;
            $block($chapter->id);
            $previousTopic = null;

            for ($t = 1; $t <= $topics; $t++) {
                $topic = $tree->createNode($course, $topicLevel, "Topic {$p}.{$c}.{$t}", $chapter, $previousTopic?->id);
                $previousTopic = $topic;
                $block($topic->id);
            }
        }
    }

    return $course->fresh();
}

/*
|--------------------------------------------------------------------------
| The snapshot builder must not scale with the tree
|--------------------------------------------------------------------------
*/

it('builds a snapshot in a constant number of queries, whatever the tree size', function () {
    $small = bigCourse(1, 1, 1);          // 3 nodes
    $large = bigCourse(6, 3, 5);          // 6 + 18 + 90 = 114 nodes

    $builder = app(SnapshotBuilder::class);

    $smallQueries = countQueries(fn () => $builder->build($small));
    $largeQueries = countQueries(fn () => $builder->build($large));

    expect($largeQueries)->toBe($smallQueries)
        ->and($largeQueries)->toBeLessThan(6);

    // And it actually built the whole tree.
    $tree = $builder->build($large)['tree'];
    expect($tree)->toHaveCount(6)
        ->and($tree[0]['children'])->toHaveCount(3)
        ->and($tree[0]['children'][0]['children'])->toHaveCount(5);
});

/** The validator walks every node and every block. It must not query per block. */
it('validates a large course in a constant number of queries', function () {
    $small = bigCourse(1, 1, 1);
    $large = bigCourse(6, 3, 5);

    $validator = app(CourseValidator::class);

    $smallQueries = countQueries(fn () => $validator->validate($small));
    $largeQueries = countQueries(fn () => $validator->validate($large));

    expect($largeQueries)->toBe($smallQueries)
        ->and($validator->errors($large))->toBe([]);
});

/** Attach a video block to every topic of the course. */
function addVideos(Course $course): int
{
    $added = 0;

    foreach ($course->nodes()->with('schemaLevel')->whereNotNull('parent_id')->get() as $node) {
        if (! $node->schemaLevel->permitsBlockType('video')) {
            continue;
        }

        $video = Media::factory()->video()->create();

        ContentBlock::create([
            'course_node_id' => $node->id,
            'type' => 'video',
            'sort_key' => FractionalIndex::between('V', null),
            'media_id' => $video->id,
            'payload' => ['media_id' => $video->id],
        ]);

        $added++;
    }

    return $added;
}

/**
 * Media is eager-loaded, so a course full of video does not become N+1.
 *
 * Eager-loading media legitimately costs one extra query, so the property that
 * matters is *constancy*: six videos and thirty videos must cost the same.
 */
it('does not query per video when building a snapshot', function () {
    $few = bigCourse(1, 1, 3);
    $many = bigCourse(2, 3, 5);

    expect(addVideos($few))->toBe(3)
        ->and(addVideos($many))->toBe(30);

    $builder = app(SnapshotBuilder::class);

    $fewQueries = countQueries(fn () => $builder->build($few->fresh()));
    $manyQueries = countQueries(fn () => $builder->build($many->fresh()));

    expect($manyQueries)->toBe($fewQueries)
        ->and($manyQueries)->toBeLessThan(8);

    // And the playback data really is denormalised into every video block.
    $topic = $builder->build($many->fresh())['tree'][0]['children'][0]['children'][0];
    $video = collect($topic['blocks'])->firstWhere('type', 'video');

    expect($video['payload']['playback_id'])->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Publishing and serving
|--------------------------------------------------------------------------
*/

it('publishes a large course and freezes the whole tree', function () {
    $course = bigCourse(8, 3, 5);        // 8 + 24 + 120 = 152 nodes

    expect($course->nodes()->count())->toBe(152);

    $publication = app(PublishCourse::class)->handle($course, User::factory()->create());

    $tree = $publication->snapshot['tree'];

    expect($tree)->toHaveCount(8)
        ->and($tree[7]['label'])->toBe('Part VIII')
        ->and($tree[0]['children'][2]['label'])->toBe('Chapter 3: Chapter 1.3')
        ->and($tree[0]['children'][0]['children'][4]['number'])->toBe('5');
});

it('serves a large snapshot in a bounded number of queries, and 304s a repeat fetch', function () {
    $course = bigCourse(6, 3, 5);
    app(PublishCourse::class)->handle($course, User::factory()->create());
    $course->refresh();

    $product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($product, $course);

    $learner = User::factory()->create();
    CompGrant::create([
        'user_id' => $learner->id,
        'product_id' => $product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);

    $queries = countQueries(function () use ($learner, $course) {
        $this->actingAs($learner)
            ->getJson("/api/v1/me/courses/{$course->id}/content")
            ->assertOk();
    });

    // The snapshot is one jsonb column: serving it must not walk the tree.
    expect($queries)->toBeLessThan(12);

    $etag = $course->fresh()->latestPublication->snapshot_etag;

    // A repeat fetch costs a 304 and no payload — this is what makes a course
    // readable by thousands of learners without touching the tree tables.
    $this->actingAs($learner)
        ->withHeader('If-None-Match', $etag)
        ->getJson("/api/v1/me/courses/{$course->id}/content")
        ->assertStatus(304);
});

/*
|--------------------------------------------------------------------------
| Tree operations stay cheap
|--------------------------------------------------------------------------
*/

/** ltree, not a recursive CTE: one query however deep the subtree. */
it('finds every descendant of a large part in one query', function () {
    $course = bigCourse(2, 3, 5);
    $part = $course->rootNodes()->first();

    $descendants = null;
    $queries = countQueries(function () use ($part, &$descendants) {
        $descendants = $part->descendants();
    });

    expect($queries)->toBe(1)
        ->and($descendants)->toHaveCount(3 + 15);
});

/**
 * Fractional indices: inserting between two siblings updates one row, not N.
 * With integer positions this is where a 40-topic chapter becomes a 40-row write
 * on every drag.
 */
it('reorders a sibling without renumbering the others', function () {
    $course = bigCourse(1, 1, 20);
    $chapter = $course->nodes()->where('title', 'Chapter 1.1')->firstOrFail();

    $topics = $chapter->children()->get();
    $keysBefore = $topics->pluck('sort_key', 'id');

    $writes = countQueries(function () use ($topics) {
        app(CourseTree::class)->reorderNode($topics->last(), $topics->first()->id);
    });

    // A handful of reads to find the neighbours, and exactly one row changed.
    expect($writes)->toBeLessThan(8);

    $changed = $chapter->children()->get()
        ->filter(fn ($node) => $keysBefore[$node->id] !== $node->sort_key);

    expect($changed)->toHaveCount(1);
});
