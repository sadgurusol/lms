<?php

use App\Authorization\Roles;
use App\Models\ContentBlock;
use App\Models\CourseGrant;
use App\Models\CourseNode;
use App\Models\Media;
use App\Models\User;
use App\Services\Tree\CourseTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('public');
});

/**
 * A Topic node (the textbook Topic level permits image/attachment) on an owned
 * course, ready to hold media.
 *
 * @return array{CourseNode, User}
 */
function topicNode(): array
{
    [$course, $part, $chapter, $topic] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    $tree = app(CourseTree::class);
    $p = $tree->appendNode($course, $part, 'Part');
    $c = $tree->appendNode($course, $chapter, 'Chapter', $p);
    $t = $tree->appendNode($course, $topic, 'Topic', $c);

    return [$t, $author];
}

/*
|--------------------------------------------------------------------------
| Uploading
|--------------------------------------------------------------------------
*/

it('uploads an image and stores a ready media record', function () {
    $author = staff(Roles::CONTENT_AUTHOR);

    $response = $this->actingAs($author)
        ->post('/studio/media', ['file' => UploadedFile::fake()->image('diagram.png', 640, 480)], ['Accept' => 'application/json'])
        ->assertCreated();

    $media = Media::sole();
    expect($media->kind)->toBe('image')
        ->and($media->status)->toBe(Media::STATUS_READY)
        ->and($media->disk)->toBe('public')
        ->and($media->checksum_sha256)->not->toBeNull();

    Storage::disk('public')->assertExists($media->path);
    $response->assertJsonPath('kind', 'image')->assertJsonPath('id', $media->id);
});

it('uploads a pdf as a document', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post('/studio/media', ['file' => UploadedFile::fake()->create('notes.pdf', 200, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated();

    expect(Media::sole()->kind)->toBe('document');
});

it('rejects an unsupported type', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post('/studio/media', ['file' => UploadedFile::fake()->create('malware.exe', 10)], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('refuses upload without the media permission', function () {
    // A reviewer holds no media.upload.
    $this->actingAs(staff(Roles::CONTENT_REVIEWER))
        ->post('/studio/media', ['file' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Attaching a media block
|--------------------------------------------------------------------------
*/

it('adds an image block referencing an uploaded asset', function () {
    [$node, $author] = topicNode();
    $mediaId = $this->actingAs($author)
        ->post('/studio/media', ['file' => UploadedFile::fake()->image('d.png')], ['Accept' => 'application/json'])
        ->json('id');

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$node->id}/content")
        ->post("/studio/course-nodes/{$node->id}/media-blocks", [
            'type' => 'image', 'media_id' => $mediaId, 'alt' => 'A diagram',
        ])
        ->assertSessionHas('success');

    $block = ContentBlock::sole();
    expect($block->type)->toBe('image')
        ->and($block->media_id)->toBe($mediaId)
        ->and($block->payload['media_id'])->toBe($mediaId)
        ->and($block->payload['alt'])->toBe('A diagram');
});

it('serves a media url with the block on the content page', function () {
    [$node, $author] = topicNode();
    $mediaId = $this->actingAs($author)
        ->post('/studio/media', ['file' => UploadedFile::fake()->image('d.png')], ['Accept' => 'application/json'])
        ->json('id');
    $this->actingAs($author)->post("/studio/course-nodes/{$node->id}/media-blocks", [
        'type' => 'image', 'media_id' => $mediaId, 'alt' => 'x',
    ]);

    $this->actingAs($author)
        ->get("/studio/course-nodes/{$node->id}/content")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.type', 'image')
            // A local disk is served through the app route, not the /storage symlink,
            // so it carries CORS headers for the cross-origin learner app.
            ->where('blocks.0.payload.url', fn (?string $url) => $url !== null && str_contains($url, "/media/{$mediaId}/file"))
        );
});

it('serves image bytes over the CORS-friendly media route', function () {
    [$node, $author] = topicNode();
    $mediaId = $this->actingAs($author)
        ->post('/studio/media', ['file' => UploadedFile::fake()->image('d.png', 40, 30)], ['Accept' => 'application/json'])
        ->json('id');

    // Public, like the old /storage URL was — no auth needed to view content images.
    $this->get("/api/v1/media/{$mediaId}/file")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('refuses an image on a level that forbids it', function () {
    // Build a chapter node: the Chapter level permits rich_text and callout only.
    [$course, $part, $chapter] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);
    $tree = app(CourseTree::class);
    $p = $tree->appendNode($course, $part, 'Part');
    $chapterNode = $tree->appendNode($course, $chapter, 'Chapter', $p);

    $mediaId = $this->actingAs($author)
        ->post('/studio/media', ['file' => UploadedFile::fake()->image('d.png')], ['Accept' => 'application/json'])->json('id');

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$chapterNode->id}/content")
        ->post("/studio/course-nodes/{$chapterNode->id}/media-blocks", [
            'type' => 'image', 'media_id' => $mediaId, 'alt' => 'x',
        ])
        ->assertSessionHas('error', 'This section does not allow that kind of content.');

    expect(ContentBlock::count())->toBe(0);
});

it('offers image and attachment on a Topic content page', function () {
    [$node, $author] = topicNode();

    $this->actingAs($author)
        ->get("/studio/course-nodes/{$node->id}/content")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('addable_types', fn ($types) => collect($types)->contains('image') && collect($types)->contains('attachment'))
        );
});
