<?php

use App\ContentBlocks\BlockType;
use App\ContentBlocks\InvalidBlockPayload;
use App\Models\ContentBlock;
use App\Models\CourseNode;
use App\Models\Media;
use App\Services\Tree\CourseTree;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$course, $part, $chapter, $topic] = textbookCourse();
    $tree = app(CourseTree::class);

    $partNode = $tree->createNode($course, $part, 'Part One');

    // Chapter permits rich_text and callout; Topic permits rich_text, video,
    // image, attachment and embed. The schema, not the code, decides.
    $this->chapterNode = $tree->createNode($course, $chapter, 'Chapter One', $partNode);
    $this->topicNode = $tree->createNode($course, $topic, 'Topic One', $this->chapterNode);
});

/** A block on the Topic node. */
function block(string $type, array $payload, ?string $mediaId = null): ContentBlock
{
    return blockOn(test()->topicNode, $type, $payload, $mediaId);
}

function blockOn(CourseNode $node, string $type, array $payload, ?string $mediaId = null): ContentBlock
{
    return ContentBlock::create([
        'course_node_id' => $node->id,
        'type' => $type,
        'sort_key' => FractionalIndex::between(null, null),
        'media_id' => $mediaId,
        'payload' => $payload,
    ]);
}

it('accepts a well formed rich_text payload', function () {
    $b = block(BlockType::RichText->value, [
        'format' => 'portable_text',
        'body' => [[
            '_type' => 'block',
            'style' => 'normal',
            'children' => [['_type' => 'span', 'text' => 'The simple past.', 'marks' => ['strong']]],
        ]],
    ]);

    expect($b->exists)->toBeTrue();
});

it('rejects rich_text in the wrong format', function () {
    expect(fn () => block(BlockType::RichText->value, ['format' => 'html', 'body' => []]))
        ->toThrow(InvalidBlockPayload::class, 'does not match its schema');
});

it('rejects rich_text with an unknown heading style', function () {
    expect(fn () => block(BlockType::RichText->value, [
        'format' => 'portable_text',
        'body' => [['_type' => 'block', 'style' => 'h1']],   // h1 is the page title, never content
    ]))->toThrow(InvalidBlockPayload::class);
});

it('rejects unknown keys rather than silently storing them', function () {
    expect(fn () => blockOn($this->chapterNode, BlockType::Callout->value, [
        'variant' => 'warning',
        'body' => [],
        'onClick' => 'alert(1)',
    ]))->toThrow(InvalidBlockPayload::class);
});

it('rejects an embed over plain http', function () {
    expect(fn () => block(BlockType::Embed->value, [
        'provider' => 'youtube',
        'url' => 'http://youtube.com/watch?v=x',
    ]))->toThrow(InvalidBlockPayload::class);

    $b = block(BlockType::Embed->value, [
        'provider' => 'youtube',
        'url' => 'https://youtube.com/watch?v=x',
    ]);

    expect($b->exists)->toBeTrue();
});

it('rejects an embed from an unapproved provider', function () {
    expect(fn () => block(BlockType::Embed->value, [
        'provider' => 'tiktok',
        'url' => 'https://tiktok.com/@x',
    ]))->toThrow(InvalidBlockPayload::class);
});

it('requires alt text on an image', function () {
    $image = Media::factory()->create();

    expect(fn () => block(BlockType::Image->value, ['media_id' => $image->id], $image->id))
        ->toThrow(InvalidBlockPayload::class, 'does not match its schema');

    $b = block(BlockType::Image->value, ['media_id' => $image->id, 'alt' => 'A tense timeline'], $image->id);
    expect($b->exists)->toBeTrue();
});

it('refuses an image block pointing at a video asset', function () {
    $video = Media::factory()->video()->create();

    expect(fn () => block(BlockType::Image->value, ['media_id' => $video->id, 'alt' => 'x'], $video->id))
        ->toThrow(InvalidBlockPayload::class, 'requires image media, but the referenced asset is video');
});

it('refuses a video block pointing at a document', function () {
    $doc = Media::factory()->document()->create();

    expect(fn () => block(BlockType::Video->value, ['media_id' => $doc->id], $doc->id))
        ->toThrow(InvalidBlockPayload::class, 'requires video media');
});

it('refuses a media reference that does not exist', function () {
    $ghost = Str::uuid7()->toString();

    expect(fn () => block(BlockType::Video->value, ['media_id' => $ghost], $ghost))
        ->toThrow(InvalidBlockPayload::class, 'does not exist');
});

/**
 * The column is what the FK and the publish-time readiness check read; the
 * payload is what the client renders. If they disagree, one of them is a lie.
 */
it('refuses a block whose media column and payload disagree', function () {
    $a = Media::factory()->video()->create();
    $b = Media::factory()->video()->create();

    expect(fn () => block(BlockType::Video->value, ['media_id' => $b->id], $a->id))
        ->toThrow(InvalidBlockPayload::class, 'on the column but');
});

it('validates on update, not only on create', function () {
    $b = blockOn($this->chapterNode, BlockType::Callout->value, ['variant' => 'tip', 'body' => []]);

    expect(fn () => $b->update(['payload' => ['variant' => 'nonsense', 'body' => []]]))
        ->toThrow(InvalidBlockPayload::class);

    expect($b->fresh()->payload['variant'])->toBe('tip');
});

/**
 * A seeder, an artisan command and a queue job all bypass FormRequests. None of
 * them bypass the model, which is why the validator lives in the saving hook.
 */
it('validates a write made straight through the model, with no request in sight', function () {
    $block = new ContentBlock([
        'course_node_id' => $this->chapterNode->id,
        'type' => BlockType::Callout->value,
        'sort_key' => FractionalIndex::between(null, null),
        'payload' => ['variant' => 'nonsense', 'body' => []],
    ]);

    expect(fn () => $block->save())->toThrow(InvalidBlockPayload::class);
});

/**
 * Honest about the boundary: JSON Schema validation is application-level and a
 * raw query-builder insert goes around it. That is exactly why the rule the
 * system cannot afford to lose — which block types a level permits — is a
 * database trigger instead.
 */
it('is bypassed by a raw query builder insert, which is why the level rule is a trigger', function () {
    $id = Str::uuid7()->toString();

    DB::table('content_blocks')->insert([
        'id' => $id,
        'course_node_id' => $this->topicNode->id,
        'type' => 'rich_text',
        'sort_key' => 'zz',
        'payload' => json_encode(['format' => 'nonsense']),   // schema-invalid, accepted
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('content_blocks')->where('id', $id)->exists())->toBeTrue();

    // But the trigger still refuses a type the level does not permit: the
    // Chapter allows only rich_text and callout.
    expectDatabaseRejection(
        fn () => DB::table('content_blocks')->insert([
            'id' => Str::uuid7()->toString(),
            'course_node_id' => $this->chapterNode->id,
            'type' => 'video',
            'sort_key' => 'zy',
            'payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'is not permitted on the level of node',
    );
});
