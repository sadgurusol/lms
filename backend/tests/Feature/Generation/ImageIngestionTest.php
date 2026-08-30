<?php

use App\Authorization\Roles;
use App\Models\Media;
use App\Services\Generation\CourseBuilder;
use App\Services\Generation\ImageIngestor;
use App\Services\Generation\LessonExpander;
use App\Services\Generation\StepMapper;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->version = publish(textbookSchema());
    $this->author = staff(Roles::CONTENT_AUTHOR);
    Storage::fake(config('filesystems.default'));
});

it('ingests a hosted image into a ready Media record', function () {
    Http::fake(['cdn.test/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png'])]);

    $media = app(ImageIngestor::class)->ingest('https://cdn.test/triangle.png', $this->author->id);

    expect($media)->not->toBeNull()
        ->and($media->kind)->toBe(Media::KIND_IMAGE)
        ->and($media->status)->toBe(Media::STATUS_READY)
        ->and($media->mime)->toBe('image/png');
    Storage::disk(config('filesystems.default'))->assertExists($media->path);
});

it('reuses an identical image instead of storing it twice', function () {
    Http::fake(['cdn.test/*' => Http::response('SAMEBYTES', 200, ['Content-Type' => 'image/png'])]);

    $first = app(ImageIngestor::class)->ingest('https://cdn.test/a.png');
    $second = app(ImageIngestor::class)->ingest('https://cdn.test/b.png');

    expect($second->id)->toBe($first->id)
        ->and(Media::count())->toBe(1);
});

it('rejects a source that is not a raster image', function () {
    Http::fake(['cdn.test/*' => Http::response('<svg/>', 200, ['Content-Type' => 'image/svg+xml'])]);

    expect(app(ImageIngestor::class)->ingest('https://cdn.test/x.svg'))->toBeNull();
    expect(Media::count())->toBe(0);
});

it('decodes an inline data URI image', function () {
    $media = app(ImageIngestor::class)->ingest('data:image/gif;base64,'.base64_encode('GIFBYTES'));

    expect($media)->not->toBeNull()
        ->and($media->mime)->toBe('image/gif');
});

it('maps a platform image block into an image spec carrying its source', function () {
    $step = ['title' => 'Triangles', 'blocks' => [
        ['type' => 'text', 'markdown' => 'A triangle has three sides.'],
        ['type' => 'image', 'url' => 'https://cdn.test/triangle.png', 'caption' => 'A right triangle'],
    ]];

    $specs = app(StepMapper::class)->blocksFor($step);
    $image = collect($specs)->firstWhere('type', 'image');

    expect($image)->not->toBeNull()
        ->and($image['payload']['src'])->toBe('https://cdn.test/triangle.png')
        ->and($image['payload']['alt'])->toBe('A right triangle');
});

it('attaches a real image block when expanding a lesson with an image step', function () {
    Http::fake(['cdn.test/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png'])]);

    // Part > Chapter (the "lesson"); Topic is the step level and allows images.
    $course = app(CourseBuilder::class)->build(
        ['nodes' => [['level' => 'Part', 'title' => 'P', 'children' => [['level' => 'Chapter', 'title' => 'Shapes']]]]],
        $this->version, 'Geometry', $this->author,
    );
    $lesson = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->sole();
    $topicLevel = $this->version->levels()->where('name', 'Topic')->sole();

    $steps = [['title' => 'The triangle', 'blocks' => [
        ['type' => 'image', 'url' => 'https://cdn.test/triangle.png', 'alt' => 'A triangle'],
    ]]];

    $created = app(LessonExpander::class)->expand($lesson, $topicLevel, $steps);

    expect($created)->toBe(1);
    $step = $lesson->children()->sole();
    $block = $step->blocks()->where('type', 'image')->sole();
    expect($block->payload['alt'])->toBe('A triangle')
        ->and(Media::whereKey($block->payload['media_id'])->value('status'))->toBe(Media::STATUS_READY);
});
