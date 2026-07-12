<?php

use App\Authorization\Roles;
use App\ContentBlocks\BlockType;
use App\Models\ContentBlock;
use App\Models\CourseGrant;
use App\Models\CourseNode;
use App\Models\User;
use App\Services\Content\BlockEditor;
use App\Services\Tree\CourseTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * An owned course with one Chapter node (which allows content) ready to fill.
 *
 * @return array{CourseNode, User}
 */
function contentNode(): array
{
    [$course, $part, $chapter] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    $tree = app(CourseTree::class);
    $partNode = $tree->createNode($course, $part, 'Part One');
    $chapterNode = $tree->createNode($course, $chapter, 'Chapter One', $partNode);

    return [$chapterNode, $author];
}

/*
|--------------------------------------------------------------------------
| Viewing
|--------------------------------------------------------------------------
*/

it('lists a node s blocks and the types it may add', function () {
    [$node, $author] = contentNode();
    app(BlockEditor::class)->create($node, BlockType::RichText->value);

    $this->actingAs($author)
        ->get("/studio/course-nodes/{$node->id}/content")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('nodes/Content')
            ->where('node.title', 'Chapter One')
            ->where('node.allows_content', true)
            ->has('blocks', 1)
            ->where('blocks.0.type', BlockType::RichText->value)
            // The Chapter level permits rich_text and callout; embed is not in
            // its allowed set, and image/video need the media pipeline.
            ->where('addable_types', ['rich_text', 'callout'])
            ->where('can.edit', true)
        );
});

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

it('adds a rich text block with a valid empty payload', function () {
    [$node, $author] = contentNode();

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$node->id}/content")
        ->post("/studio/course-nodes/{$node->id}/content", [
            'type' => BlockType::RichText->value,
            'after_block_id' => null,
        ])
        ->assertSessionHas('success');

    $block = ContentBlock::sole();
    expect($block->type)->toBe(BlockType::RichText->value)
        ->and($block->payload)->toEqual(['format' => 'portable_text', 'body' => []]);
});

it('adds a callout with a valid default', function () {
    [$node, $author] = contentNode();

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$node->id}/content")
        ->post("/studio/course-nodes/{$node->id}/content", ['type' => BlockType::Callout->value]);

    expect(ContentBlock::sole()->payload)->toEqual(['variant' => 'info', 'body' => []]);
});

it('refuses a block type the level does not permit', function () {
    [$node, $author] = contentNode();

    // The Chapter level permits rich_text and callout, not embed.
    $this->actingAs($author)
        ->from("/studio/course-nodes/{$node->id}/content")
        ->post("/studio/course-nodes/{$node->id}/content", ['type' => BlockType::Embed->value])
        ->assertSessionHas('error', 'This section does not allow that kind of content.');

    expect(ContentBlock::count())->toBe(0);
});

it('refuses a media-backed block type outright', function () {
    [$node, $author] = contentNode();

    // Even though the DB might permit it on some level, the editor cannot author
    // it without the media pipeline, so the request is rejected at validation.
    $this->actingAs($author)
        ->post("/studio/course-nodes/{$node->id}/content", ['type' => BlockType::Video->value])
        ->assertSessionHasErrors('type');
});

/*
|--------------------------------------------------------------------------
| Editing payloads
|--------------------------------------------------------------------------
*/

it('saves rich text as portable text', function () {
    [$node, $author] = contentNode();
    $block = app(BlockEditor::class)->create($node, BlockType::RichText->value);

    $body = [
        [
            '_type' => 'block',
            'style' => 'normal',
            'markDefs' => [],
            'children' => [['_type' => 'span', 'text' => 'Hello world', 'marks' => []]],
        ],
    ];

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$node->id}/content")
        ->patch("/studio/content-blocks/{$block->id}", [
            'payload' => ['format' => 'portable_text', 'body' => $body],
        ])
        ->assertSessionHas('success');

    expect($block->fresh()->payload['body'][0]['children'][0]['text'])->toBe('Hello world');
});

it('saves a valid embed', function () {
    // The Topic level permits embed; build a topic to host it.
    [$course, , $chapterLevel, $topicLevel] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);
    $tree = app(CourseTree::class);
    $part = $tree->createNode($course, $course->schemaVersion->levels()->where('name', 'Part')->sole(), 'P');
    $chapter = $tree->createNode($course, $chapterLevel, 'C', $part);
    $topic = $tree->createNode($course, $topicLevel, 'T', $chapter);
    $block = app(BlockEditor::class)->create($topic, BlockType::Embed->value);

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$topic->id}/content")
        ->patch("/studio/content-blocks/{$block->id}", [
            'payload' => [
                'provider' => 'youtube',
                'url' => 'https://www.youtube.com/embed/abc',
                'aspect_ratio' => '16:9',
            ],
        ])
        ->assertSessionHas('success');

    expect($block->fresh()->payload['url'])->toBe('https://www.youtube.com/embed/abc');
});

/** The saving hook validates shape; the controller turns a failure into a field error. */
it('rejects an embed with a non-https url', function () {
    [$course, , $chapterLevel, $topicLevel] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);
    $tree = app(CourseTree::class);
    $part = $tree->createNode($course, $course->schemaVersion->levels()->where('name', 'Part')->sole(), 'P');
    $chapter = $tree->createNode($course, $chapterLevel, 'C', $part);
    $topic = $tree->createNode($course, $topicLevel, 'T', $chapter);
    $block = app(BlockEditor::class)->create($topic, BlockType::Embed->value);

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$topic->id}/content")
        ->patch("/studio/content-blocks/{$block->id}", [
            'payload' => ['provider' => 'youtube', 'url' => 'http://insecure.example'],
        ])
        ->assertSessionHasErrors('payload');

    // The bad edit did not land.
    expect($block->fresh()->payload['url'])->toBe('https://');
});

/*
|--------------------------------------------------------------------------
| Reordering and deleting
|--------------------------------------------------------------------------
*/

it('appends new blocks in creation order', function () {
    [$node, $author] = contentNode();

    foreach (range(1, 3) as $_) {
        $this->actingAs($author)
            ->post("/studio/course-nodes/{$node->id}/content", ['type' => BlockType::RichText->value]);
    }

    // Byte-wise sort_key order matches creation order: each block appended, not
    // head-inserted.
    $bySort = ContentBlock::where('course_node_id', $node->id)->orderBy('sort_key')->pluck('id')->all();
    $byCreated = ContentBlock::where('course_node_id', $node->id)->orderBy('created_at')->pluck('id')->all();

    expect($bySort)->toBe($byCreated);
});

it('reorders blocks', function () {
    [$node, $author] = contentNode();
    $editor = app(BlockEditor::class);
    $a = $editor->create($node, BlockType::RichText->value);
    $b = $editor->create($node, BlockType::Callout->value, $a->id);

    // Move A after B.
    $this->actingAs($author)
        ->from("/studio/course-nodes/{$node->id}/content")
        ->post("/studio/content-blocks/{$a->id}/move", ['after_block_id' => $b->id])
        ->assertSessionHas('success');

    $order = ContentBlock::where('course_node_id', $node->id)->orderBy('sort_key')->pluck('id')->all();
    expect($order)->toBe([$b->id, $a->id]);
});

it('deletes a block', function () {
    [$node, $author] = contentNode();
    $block = app(BlockEditor::class)->create($node, BlockType::RichText->value);

    $this->actingAs($author)
        ->from("/studio/course-nodes/{$node->id}/content")
        ->delete("/studio/content-blocks/{$block->id}")
        ->assertSessionHas('success');

    expect(ContentBlock::count())->toBe(0)
        ->and(ContentBlock::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('refuses block creation without an editing grant', function () {
    [$node] = contentNode();
    $stranger = staff(Roles::CONTENT_AUTHOR);

    $this->actingAs($stranger)
        ->post("/studio/course-nodes/{$node->id}/content", ['type' => BlockType::RichText->value])
        ->assertForbidden();

    expect(ContentBlock::count())->toBe(0);
});

it('shows a granted reviewer read-only content', function () {
    [$node] = contentNode();
    app(BlockEditor::class)->create($node, BlockType::RichText->value);

    $reviewer = staff(Roles::CONTENT_REVIEWER);
    grant($reviewer, $node->course, CourseGrant::REVIEWER);

    $this->actingAs($reviewer)
        ->get("/studio/course-nodes/{$node->id}/content")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.edit', false)
            ->has('blocks', 1)
        );
});

it('refuses a reviewer any edit', function () {
    [$node] = contentNode();
    $block = app(BlockEditor::class)->create($node, BlockType::RichText->value);

    $reviewer = staff(Roles::CONTENT_REVIEWER);
    grant($reviewer, $node->course, CourseGrant::REVIEWER);

    $this->actingAs($reviewer)
        ->patch("/studio/content-blocks/{$block->id}", [
            'payload' => ['format' => 'portable_text', 'body' => []],
        ])
        ->assertForbidden();
});

it('sends a guest away', function () {
    [$node] = contentNode();

    $this->get("/studio/course-nodes/{$node->id}/content")->assertRedirect('/studio/login');
});
