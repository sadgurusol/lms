<?php

use App\Authorization\Roles;
use App\ContentBlocks\BlockType;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\Media;
use App\Models\ReviewComment;
use App\Models\User;
use App\Services\Media\CompleteMediaUpload;
use App\Services\Media\RequestMediaUpload;
use App\Services\Media\UploadUrlGenerator;
use App\Services\Publishing\CourseValidator;
use App\Services\Publishing\PublishBlocked;
use App\Services\Publishing\PublishCourse;
use App\Services\Review\ReviewWorkflow;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * The M3 + M4 acceptance criteria, driven end to end through the real services.
 *
 * An author uploads a video, builds a course, submits it; a reviewer comments on
 * a specific block and requests changes; the author fixes and resubmits; the
 * reviewer approves; an admin publishes; and a rollback restores publication 1
 * in a single update.
 */
it('carries a course from an empty tree to a published snapshot and back again', function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->swap(UploadUrlGenerator::class, new class implements UploadUrlGenerator
    {
        public function presign(string $disk, string $path, string $mime, DateTimeInterface $expiresAt): array
        {
            return ['url' => 'https://bucket.test/'.$path, 'headers' => []];
        }
    });

    [$course, $partLevel, $chapterLevel, $topicLevel] = textbookCourse();
    $tree = app(CourseTree::class);
    $workflow = app(ReviewWorkflow::class);

    $author = User::factory()->withRole(Roles::CONTENT_AUTHOR)->create();
    $reviewer = User::factory()->withRole(Roles::CONTENT_REVIEWER)->create();
    $admin = User::factory()->withRole(Roles::ADMIN)->create();

    grant($author, $course, CourseGrant::OWNER);
    grant($reviewer, $course, CourseGrant::REVIEWER);

    // ---- M3: the author uploads a video, which transcodes asynchronously ----
    $bytes = 'lecture-bytes';
    ['media' => $video] = app(RequestMediaUpload::class)
        ->handle($author, 'lecture.mp4', 'video/mp4', strlen($bytes));

    Storage::disk($video->disk)->put($video->path, $bytes);
    $video = app(CompleteMediaUpload::class)->handle($video, hash('sha256', $bytes));

    expect($video->status)->toBe(Media::STATUS_PROCESSING);

    // ---- The author builds the tree ----
    $part = $tree->createNode($course, $partLevel, 'Language & Grammar');
    $chapter = $tree->createNode($course, $chapterLevel, 'Tenses', $part);
    $topic = $tree->createNode($course, $topicLevel, 'Simple Past', $chapter);

    $intro = ContentBlock::create([
        'course_node_id' => $chapter->id,
        'type' => BlockType::RichText->value,
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);

    $videoBlock = ContentBlock::create([
        'course_node_id' => $topic->id,
        'type' => BlockType::Video->value,
        'sort_key' => FractionalIndex::between(null, null),
        'media_id' => $video->id,
        'payload' => ['media_id' => $video->id],
    ]);

    // Publishing is blocked while the video is still transcoding.
    expect(fn () => app(PublishCourse::class)->handle($course->fresh(), $admin))
        ->toThrow(PublishBlocked::class);

    // The transcoder's webhook lands.
    $video->update(['status' => Media::STATUS_READY, 'playback_id' => 'play-abc', 'duration_s' => 412]);

    // ---- M4: submit, review, request changes ----
    $request = $workflow->submit($course->fresh(), $author, $reviewer);
    expect($course->fresh()->workflow_state)->toBe(Course::STATE_IN_REVIEW);

    $comment = $workflow->comment(
        $request, $reviewer, 'This video needs captions.',
        ReviewComment::ANCHOR_BLOCK, $videoBlock->id,
    );

    $workflow->requestChanges($request, $reviewer, 'Add captions first.');
    expect($course->fresh()->workflow_state)->toBe(Course::STATE_CHANGES_REQUESTED);

    // ---- The author fixes it and resubmits ----
    $videoBlock->update(['payload' => [
        'media_id' => $video->id,
        'captions' => [['lang' => 'en', 'url' => 'https://cdn.test/en.vtt']],
    ]]);
    $workflow->resolve($comment, $author);

    $request2 = $workflow->submit($course->fresh(), $author, $reviewer);
    expect($request2->id)->not->toBe($request->id);

    // The reviewer approves. The author cannot: separation of duties.
    expect(Gate::forUser($author)->allows('review', $course))->toBeFalse();
    $workflow->approve($request2, $reviewer);
    expect($course->fresh()->workflow_state)->toBe(Course::STATE_APPROVED);

    // ---- Only an admin publishes ----
    expect(Gate::forUser($author)->allows('publish', $course))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('publish', $course))->toBeTrue()
        ->and(app(CourseValidator::class)->errors($course->fresh()))->toBe([]);

    $first = app(PublishCourse::class)->handle($course->fresh(), $admin, 'Initial release');

    expect($first->number)->toBe(1)
        ->and($course->fresh()->latest_publication_id)->toBe($first->id)
        ->and($first->snapshot['tree'][0]['label'])->toBe('Part I')
        ->and($first->snapshot['tree'][0]['children'][0]['label'])->toBe('Chapter 1: Tenses')
        ->and($first->media_manifest)->toHaveCount(1)
        ->and($first->media_manifest[0]['playback_id'])->toBe('play-abc');

    // ---- The author starts on v2; learners are unaffected ----
    $part2 = $tree->createNode($course->fresh(), $partLevel, 'Composition');
    $chapter2 = $tree->createNode($course->fresh(), $chapterLevel, 'Essays', $part2);
    ContentBlock::create([
        'course_node_id' => $chapter2->id,
        'type' => BlockType::RichText->value,
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);
    $topic2 = $tree->createNode($course->fresh(), $topicLevel, 'Structure', $chapter2);
    ContentBlock::create([
        'course_node_id' => $topic2->id,
        'type' => BlockType::RichText->value,
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['format' => 'portable_text', 'body' => []],
    ]);

    expect($course->fresh()->workflow_state)->toBe(Course::STATE_DRAFT)
        // Publication 1 still reads exactly as published.
        ->and($course->fresh()->latest_publication_id)->toBe($first->id)
        ->and($first->fresh()->snapshot['tree'])->toHaveCount(1);

    $second = app(PublishCourse::class)->handle($course->fresh(), $admin);

    expect($second->number)->toBe(2)
        ->and($second->changelog)->toBe('3 node(s) added.')
        ->and($second->snapshot['tree'])->toHaveCount(2)
        ->and($second->snapshot['tree'][1]['label'])->toBe('Part II')
        ->and($second->snapshot_etag)->not->toBe($first->snapshot_etag);

    // ---- Rollback: one update, nothing destroyed ----
    app(PublishCourse::class)->promote($first, $admin);

    expect($course->fresh()->latest_publication_id)->toBe($first->id)
        ->and($course->fresh()->publications()->count())->toBe(2)
        ->and($intro->fresh()->exists)->toBeTrue();
});
