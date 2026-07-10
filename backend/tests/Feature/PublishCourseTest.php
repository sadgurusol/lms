<?php

use App\ContentBlocks\BlockType;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Models\Media;
use App\Models\User;
use App\Services\Publishing\CourseValidator;
use App\Services\Publishing\PublishBlocked;
use App\Services\Publishing\PublishCourse;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->course, $this->partLevel, $this->chapterLevel, $this->topicLevel] = textbookCourse();
    $this->tree = app(CourseTree::class);
    $this->admin = User::factory()->create();
});

/** Appends after the node's existing blocks — sibling sort keys must be unique. */
function addBlock(string $nodeId, string $type, array $payload, ?string $mediaId = null): ContentBlock
{
    $last = ContentBlock::where('course_node_id', $nodeId)
        ->orderByDesc('sort_key')
        ->value('sort_key');

    return ContentBlock::create([
        'course_node_id' => $nodeId,
        'type' => $type,
        'sort_key' => FractionalIndex::between($last, null),
        'media_id' => $mediaId,
        'payload' => $payload,
    ]);
}

function richText(string $text = 'Hello'): array
{
    return [
        'format' => 'portable_text',
        'body' => [['_type' => 'block', 'style' => 'normal',
            'children' => [['_type' => 'span', 'text' => $text]]]],
    ];
}

/** A minimal publishable course: one Part, one Chapter with content, one Topic with content. */
function completeCourse(): array
{
    $t = test();
    $part = $t->tree->createNode($t->course, $t->partLevel, 'Language & Grammar');
    $chapter = $t->tree->createNode($t->course, $t->chapterLevel, 'Tenses', $part);
    $topic = $t->tree->createNode($t->course, $t->topicLevel, 'Simple Past', $chapter);

    addBlock($chapter->id, BlockType::RichText->value, richText('Chapter intro'));
    addBlock($topic->id, BlockType::RichText->value, richText('The simple past.'));

    return [$part, $chapter, $topic];
}

function doPublish(?string $changelog = null): CoursePublication
{
    return app(PublishCourse::class)->handle(test()->course->fresh(), test()->admin, $changelog);
}

/*
|--------------------------------------------------------------------------
| The validator (I12: cardinality lives here, not in a trigger)
|--------------------------------------------------------------------------
*/

it('blocks publication of an empty course', function () {
    $findings = app(CourseValidator::class)->validate($this->course);

    expect(collect($findings)->pluck('code')->all())->toContain('E_ORPHAN_LEVEL');
    expect(fn () => doPublish())->toThrow(PublishBlocked::class, 'not publishable');
});

it('reports a Part with no Chapters as an error, anchored to that node', function () {
    $part = $this->tree->createNode($this->course, $this->partLevel, 'Empty Part');

    $finding = collect(app(CourseValidator::class)->validate($this->course))
        ->firstWhere('code', 'E_MIN_OCCURRENCES');

    expect($finding)->not->toBeNull()
        ->and($finding->message)->toContain('Empty Part')
        ->and($finding->message)->toContain('at least 1 Chapters')
        ->and($finding->anchorId)->toBe($part->id)
        ->and($finding->isError())->toBeTrue();
});

it('lets an author create an empty Part and fill it later', function () {
    // The whole reason cardinality is a publish-gate check and not a trigger.
    $part = $this->tree->createNode($this->course, $this->partLevel, 'Part One');
    expect($part->exists)->toBeTrue();

    $chapter = $this->tree->createNode($this->course, $this->chapterLevel, 'Chapter One', $part);
    addBlock($chapter->id, BlockType::RichText->value, richText());
    $topic = $this->tree->createNode($this->course, $this->topicLevel, 'Topic One', $chapter);
    addBlock($topic->id, BlockType::RichText->value, richText());

    expect(app(CourseValidator::class)->errors($this->course))->toBe([]);
});

it('reports a content-bearing leaf with no blocks', function () {
    $part = $this->tree->createNode($this->course, $this->partLevel, 'Part');
    $chapter = $this->tree->createNode($this->course, $this->chapterLevel, 'Chapter', $part);
    addBlock($chapter->id, BlockType::RichText->value, richText());
    $topic = $this->tree->createNode($this->course, $this->topicLevel, 'Empty Topic', $chapter);

    $codes = collect(app(CourseValidator::class)->validate($this->course))->pluck('code');

    expect($codes)->toContain('E_EMPTY_LEAF');

    $finding = collect(app(CourseValidator::class)->validate($this->course))->firstWhere('code', 'E_EMPTY_LEAF');
    expect($finding->anchorId)->toBe($topic->id);
});

it('does not report a Chapter with children but no blocks as an empty leaf', function () {
    // A Chapter allows content but is not a leaf. Only dead ends are errors.
    $part = $this->tree->createNode($this->course, $this->partLevel, 'Part');
    $chapter = $this->tree->createNode($this->course, $this->chapterLevel, 'Chapter', $part);
    $topic = $this->tree->createNode($this->course, $this->topicLevel, 'Topic', $chapter);
    addBlock($topic->id, BlockType::RichText->value, richText());

    $codes = collect(app(CourseValidator::class)->validate($this->course))->pluck('code');

    expect($codes)->not->toContain('E_EMPTY_LEAF');
});

it('blocks publication while a video is still transcoding', function () {
    [, , $topic] = completeCourse();
    $video = Media::factory()->video()->processing()->create();

    addBlock($topic->id, BlockType::Video->value, ['media_id' => $video->id], $video->id);

    $finding = collect(app(CourseValidator::class)->validate($this->course))
        ->firstWhere('code', 'E_MEDIA_NOT_READY');

    expect($finding)->not->toBeNull()
        ->and($finding->message)->toContain('processing');

    expect(fn () => doPublish())->toThrow(PublishBlocked::class);
});

it('catches a schema-invalid payload smuggled in by a raw insert', function () {
    [, , $topic] = completeCourse();

    DB::table('content_blocks')->insert([
        'id' => Str::uuid7()->toString(),
        'course_node_id' => $topic->id,
        'type' => 'rich_text',
        'sort_key' => 'zzz',
        'payload' => json_encode(['format' => 'html']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $codes = collect(app(CourseValidator::class)->validate($this->course))->pluck('code');

    expect($codes)->toContain('E_BLOCK_SCHEMA');
    expect(fn () => doPublish())->toThrow(PublishBlocked::class);
});

/*
|--------------------------------------------------------------------------
| Warnings never block
|--------------------------------------------------------------------------
*/

it('warns about a missing alt text but still publishes', function () {
    [, , $topic] = completeCourse();
    $image = Media::factory()->create();

    addBlock($topic->id, BlockType::Image->value, ['media_id' => $image->id, 'alt' => ' '], $image->id);

    $findings = collect(app(CourseValidator::class)->validate($this->course));

    expect($findings->pluck('code'))->toContain('W_MISSING_ALT')
        ->and(app(CourseValidator::class)->errors($this->course))->toBe([]);

    expect(doPublish()->number)->toBe(1);
});

it('warns about a video with no captions but still publishes', function () {
    [, , $topic] = completeCourse();
    $video = Media::factory()->video()->create();

    addBlock($topic->id, BlockType::Video->value, ['media_id' => $video->id], $video->id);

    expect(collect(app(CourseValidator::class)->validate($this->course))->pluck('code'))
        ->toContain('W_NO_CAPTIONS');

    expect(doPublish()->number)->toBe(1);
});

it('sorts errors ahead of warnings', function () {
    $this->tree->createNode($this->course, $this->partLevel, 'Empty Part');

    $findings = app(CourseValidator::class)->validate($this->course);

    expect($findings[0]->isError())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The snapshot
|--------------------------------------------------------------------------
*/

it('publishes a complete course and freezes a snapshot', function () {
    completeCourse();

    $publication = doPublish('First cut');

    expect($publication->number)->toBe(1)
        ->and($publication->snapshot_etag)->toHaveLength(64)
        ->and($this->course->fresh()->workflow_state)->toBe(Course::STATE_PUBLISHED)
        ->and($this->course->fresh()->latest_publication_id)->toBe($publication->id);

    $tree = $publication->snapshot['tree'];

    expect($tree)->toHaveCount(1)
        ->and($tree[0]['title'])->toBe('Language & Grammar')
        ->and($tree[0]['children'][0]['title'])->toBe('Tenses')
        ->and($tree[0]['children'][0]['children'][0]['title'])->toBe('Simple Past');
});

/** Baked in at publish. Two clients must never disagree about "Chapter 3". */
it('bakes numbering and labels into the snapshot', function () {
    $part = $this->tree->createNode($this->course, $this->partLevel, 'Language');
    $c1 = $this->tree->createNode($this->course, $this->chapterLevel, 'Tenses', $part);
    $c2 = $this->tree->createNode($this->course, $this->chapterLevel, 'Voice', $part, afterNodeId: $c1->id);

    foreach ([$c1, $c2] as $chapter) {
        addBlock($chapter->id, BlockType::RichText->value, richText());
        $topic = $this->tree->createNode($this->course, $this->topicLevel, "Topic of {$chapter->title}", $chapter);
        addBlock($topic->id, BlockType::RichText->value, richText());
    }

    $tree = doPublish()->snapshot['tree'];

    expect($tree[0]['number'])->toBe('I')                       // Part: roman
        ->and($tree[0]['label'])->toBe('Part I')
        ->and($tree[0]['children'][0]['label'])->toBe('Chapter 1: Tenses')
        ->and($tree[0]['children'][1]['label'])->toBe('Chapter 2: Voice')
        // Topic numbering restarts inside each Chapter.
        ->and($tree[0]['children'][0]['children'][0]['number'])->toBe('1')
        ->and($tree[0]['children'][1]['children'][0]['number'])->toBe('1');
});

it('orders siblings in the snapshot by sort key, byte-wise', function () {
    $part = $this->tree->createNode($this->course, $this->partLevel, 'P');
    $chapter = $this->tree->createNode($this->course, $this->chapterLevel, 'C', $part);
    addBlock($chapter->id, BlockType::RichText->value, richText());

    $a = $this->tree->createNode($this->course, $this->topicLevel, 'A', $chapter);
    $c = $this->tree->createNode($this->course, $this->topicLevel, 'C', $chapter, afterNodeId: $a->id);
    $b = $this->tree->createNode($this->course, $this->topicLevel, 'B', $chapter, afterNodeId: $a->id);

    foreach ([$a, $b, $c] as $t) {
        addBlock($t->id, BlockType::RichText->value, richText());
    }

    $topics = doPublish()->snapshot['tree'][0]['children'][0]['children'];

    expect(array_column($topics, 'title'))->toBe(['A', 'B', 'C']);
});

it('builds a deduplicated media manifest', function () {
    [, , $topic] = completeCourse();
    $video = Media::factory()->video()->create();
    $image = Media::factory()->create();

    addBlock($topic->id, BlockType::Video->value, ['media_id' => $video->id], $video->id);
    addBlock($topic->id, BlockType::Image->value, ['media_id' => $image->id, 'alt' => 'x'], $image->id);
    addBlock($topic->id, BlockType::Image->value, ['media_id' => $image->id, 'alt' => 'again'], $image->id);

    $manifest = doPublish()->media_manifest;

    expect($manifest)->toHaveCount(2)
        ->and(collect($manifest)->pluck('media_id')->sort()->values()->all())
        ->toBe(collect([$video->id, $image->id])->sort()->values()->all());
});

it('denormalises playback data into video blocks so an offline pack is self contained', function () {
    [, , $topic] = completeCourse();
    $video = Media::factory()->video()->create(['playback_id' => 'play-xyz', 'duration_s' => 412]);

    addBlock($topic->id, BlockType::Video->value, ['media_id' => $video->id], $video->id);

    $blocks = doPublish()->snapshot['tree'][0]['children'][0]['children'][0]['blocks'];
    $videoBlock = collect($blocks)->firstWhere('type', 'video');

    expect($videoBlock['payload']['playback_id'])->toBe('play-xyz')
        ->and($videoBlock['payload']['duration_s'])->toBe(412);
});

/*
|--------------------------------------------------------------------------
| Immutability, numbering, rollback
|--------------------------------------------------------------------------
*/

it('refuses to mutate a publication, at the database level', function () {
    completeCourse();
    $publication = doPublish();

    expectDatabaseRejection(
        fn () => DB::table('course_publications')->where('id', $publication->id)
            ->update(['changelog' => 'rewritten history']),
        'course publications are immutable',
    );
});

/** A fully populated Part, ready to publish. */
function addPart(string $title): void
{
    $t = test();
    $part = $t->tree->createNode($t->course->fresh(), $t->partLevel, $title);
    $chapter = $t->tree->createNode($t->course->fresh(), $t->chapterLevel, "{$title} chapter", $part);
    addBlock($chapter->id, BlockType::RichText->value, richText());
    $topic = $t->tree->createNode($t->course->fresh(), $t->topicLevel, "{$title} topic", $chapter);
    addBlock($topic->id, BlockType::RichText->value, richText());
}

it('increments the publication number monotonically', function () {
    completeCourse();

    expect(doPublish()->number)->toBe(1);

    addPart('Part Two');

    expect(doPublish()->number)->toBe(2)
        ->and($this->course->fresh()->publications()->count())->toBe(2);
});

it('leaves the published snapshot untouched when an author edits the draft', function () {
    completeCourse();
    $publication = doPublish();

    $this->tree->createNode($this->course->fresh(), $this->partLevel, 'A new part, half written');

    expect($this->course->fresh()->workflow_state)->toBe(Course::STATE_DRAFT)
        // Learners keep reading publication 1; the half-written part is invisible.
        ->and($this->course->fresh()->latest_publication_id)->toBe($publication->id)
        ->and($publication->fresh()->snapshot['tree'])->toHaveCount(1);
});

it('rolls back to an earlier publication with one update', function () {
    completeCourse();
    $first = doPublish();

    addPart('Part Two');

    $second = doPublish();
    expect($this->course->fresh()->latest_publication_id)->toBe($second->id);

    app(PublishCourse::class)->promote($first, $this->admin);

    expect($this->course->fresh()->latest_publication_id)->toBe($first->id)
        ->and($this->course->fresh()->workflow_state)->toBe(Course::STATE_PUBLISHED)
        // Nothing was destroyed: publication 2 is still there to roll forward to.
        ->and(CoursePublication::count())->toBe(2);
});

it('writes a changelog by diffing against the previous snapshot', function () {
    completeCourse();

    expect(doPublish()->changelog)->toBe('Initial publication.');

    addPart('Part Two');

    expect(doPublish()->changelog)->toBe('3 node(s) added.');
});

it('honours an explicit changelog', function () {
    completeCourse();

    expect(doPublish('Fixed the tenses chapter')->changelog)->toBe('Fixed the tenses chapter');
});
