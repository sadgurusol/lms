<?php

use App\Authorization\Roles;
use App\Models\ContentBlock;
use App\Models\CourseGrant;
use App\Models\CoursePublication;
use App\Models\Media;
use App\Models\User;
use App\Services\Publishing\PublishCourse;
use App\Services\Tree\CourseTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    config(['media.transcoder' => 'local']);
});

/** A Topic node (which permits video) on an owned course, plus its owner. */
function videoTopic(): array
{
    [$course, $part, $chapter, $topic] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    $tree = app(CourseTree::class);
    $p = $tree->appendNode($course, $part, 'Part');
    $c = $tree->appendNode($course, $chapter, 'Chapter', $p);
    $t = $tree->appendNode($course, $topic, 'Topic', $c);

    return [$t, $author, $course];
}

/** Drive the three-step upload to a processing video. Returns the media. */
function uploadVideo(User $author, string $bytes = 'fake-mp4-bytes'): Media
{
    $request = test()->actingAs($author)
        ->postJson('/studio/media/uploads', [
            'filename' => 'lecture.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => strlen($bytes),
        ])
        ->assertCreated();

    $media = Media::findOrFail($request->json('id'));

    // The "bucket" in dev is our own proxy; PUT the raw bytes there.
    test()->actingAs($author)
        ->call('PUT', '/studio/media/blob?path='.urlencode($media->path), content: $bytes)
        ->assertNoContent();

    test()->actingAs($author)
        ->postJson("/studio/media/uploads/{$media->id}/complete", ['checksum' => hash('sha256', $bytes)])
        ->assertOk()
        ->assertJsonPath('status', Media::STATUS_PROCESSING);

    return $media->refresh();
}

it('requests an upload target and a pending video row', function () {
    [, $author] = videoTopic();

    $this->actingAs($author)
        ->postJson('/studio/media/uploads', ['filename' => 'lecture.mp4', 'mime' => 'video/mp4', 'size_bytes' => 1024])
        ->assertCreated()
        ->assertJsonPath('kind', Media::KIND_VIDEO)
        ->assertJsonPath('status', Media::STATUS_UPLOADING)
        ->assertJsonPath('playback', null)
        ->assertJson(fn ($json) => $json->has('upload_url')->has('headers')->etc());
});

it('puts a video into processing and submits it to the transcoder', function () {
    [, $author] = videoTopic();

    $media = uploadVideo($author);

    expect($media->status)->toBe(Media::STATUS_PROCESSING)
        ->and($media->provider)->toBe('local')
        ->and($media->provider_asset_id)->toStartWith('local_');
    Storage::disk('local')->assertExists($media->path);
});

it('refuses the upload flow without the media permission', function () {
    $reviewer = staff(Roles::CONTENT_REVIEWER);

    $this->actingAs($reviewer)
        ->postJson('/studio/media/uploads', ['filename' => 'x.mp4', 'mime' => 'video/mp4', 'size_bytes' => 10])
        ->assertForbidden();
});

it('will not attach a video block until the asset is ready', function () {
    [$node, $author] = videoTopic();
    $media = uploadVideo($author);

    // Still processing — attaching must be refused.
    $this->actingAs($author)
        ->post("/studio/course-nodes/{$node->id}/media-blocks", ['type' => 'video', 'media_id' => $media->id])
        ->assertStatus(422);

    expect(ContentBlock::count())->toBe(0);
});

it('readies a processing video and exposes a playback source', function () {
    [, $author] = videoTopic();
    $media = uploadVideo($author);

    $this->artisan('media:ready', ['media' => $media->id])->assertSuccessful();

    $this->actingAs($author)
        ->getJson("/studio/media/{$media->id}")
        ->assertOk()
        ->assertJsonPath('status', Media::STATUS_READY)
        ->assertJsonPath('playback.src_type', 'mp4')
        ->assertJsonPath('playback.src', fn (string $src) => str_contains($src, "/media/{$media->id}/stream"));
});

it('attaches a video block and bakes its playback into the snapshot', function () {
    [$node, $author, $course] = videoTopic();
    $media = uploadVideo($author);
    $this->artisan('media:ready', ['media' => $media->id])->assertSuccessful();

    // Give the Topic some readable text too, so the course is publishable.
    $this->actingAs($author)->post("/studio/course-nodes/{$node->id}/content", ['type' => 'rich_text']);

    $this->actingAs($author)
        ->post("/studio/course-nodes/{$node->id}/media-blocks", ['type' => 'video', 'media_id' => $media->id])
        ->assertSessionHas('success');

    $block = ContentBlock::where('type', 'video')->sole();
    expect($block->media_id)->toBe($media->id);

    app(PublishCourse::class)->handle($course->fresh(), $author);
    $snapshot = CoursePublication::where('course_id', $course->id)->sole()->snapshot;

    $videoBlock = collect(data_get($snapshot, 'tree.0.children.0.children.0.blocks'))
        ->firstWhere('type', 'video');

    expect($videoBlock)->not->toBeNull()
        ->and($videoBlock['payload']['src_type'])->toBe('mp4')
        ->and($videoBlock['payload']['src'])->toContain("/media/{$media->id}/stream")
        ->and($videoBlock['payload']['duration_s'])->toBe(180);
});

it('streams the bytes of a ready local video, honouring range', function () {
    [, $author] = videoTopic();
    $bytes = 'the-actual-video-bytes';
    $media = uploadVideo($author, $bytes);
    $this->artisan('media:ready', ['media' => $media->id])->assertSuccessful();

    $learner = User::factory()->create();

    // A range request must come back as 206 Partial Content.
    $this->actingAs($learner, 'sanctum')
        ->get("/api/v1/media/{$media->id}/stream", ['Range' => 'bytes=0-3'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-3/'.strlen($bytes));
});

it('does not stream a video that is still processing', function () {
    [, $author] = videoTopic();
    $media = uploadVideo($author); // processing, not ready

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->get("/api/v1/media/{$media->id}/stream")
        ->assertNotFound();
});
