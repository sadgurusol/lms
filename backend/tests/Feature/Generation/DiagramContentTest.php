<?php

use App\Authorization\Roles;
use App\Services\Generation\ContentWriter;
use App\Services\Generation\CourseBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->author = staff(Roles::CONTENT_AUTHOR);

    // Let the Topic level carry diagram blocks (must be set before publishing —
    // published levels are immutable). Chapter stays without, for the negative case.
    $version = textbookSchema();
    $topicLevel = $version->levels()->where('name', 'Topic')->sole();
    $topicLevel->update(['allowed_block_types' => [...$topicLevel->allowed_block_types, 'diagram']]);
    $this->version = publish($version);

    $course = app(CourseBuilder::class)->build(
        ['nodes' => [['level' => 'Part', 'title' => 'P', 'children' => [
            ['level' => 'Chapter', 'title' => 'C', 'children' => [['level' => 'Topic', 'title' => 'T']]],
        ]]]],
        $this->version, 'Geometry', $this->author,
    );
    $this->topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->sole();
    $this->chapter = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->sole();
});

it('extracts an inline SVG diagram into a diagram block and keeps the prose', function () {
    $content = "A triangle has three sides.\n\n"
        ."```svg\n<svg viewBox=\"0 0 10 10\"><polygon points=\"0,10 5,0 10,10\"/></svg>\n```\n\n"
        .'That is the shape.';

    app(ContentWriter::class)->write($this->topic, $this->topic->schemaLevel, $content);

    $diagram = $this->topic->blocks()->where('type', 'diagram')->sole();
    expect($diagram->payload['format'])->toBe('svg')
        ->and($diagram->payload['svg'])->toContain('<polygon');

    // The prose block no longer contains the raw SVG.
    $rich = $this->topic->blocks()->where('type', 'rich_text')->sole();
    $text = collect($rich->payload['body'])->flatMap(fn ($b) => $b['children'] ?? [])->pluck('text')->implode(' ');
    expect($text)->toContain('three sides')->not->toContain('<svg');
});

it('strips scripts from a generated diagram', function () {
    $content = "See below.\n\n```svg\n<svg viewBox=\"0 0 1 1\"><script>alert(1)</script><rect/></svg>\n```";

    app(ContentWriter::class)->write($this->topic, $this->topic->schemaLevel, $content);

    $svg = $this->topic->blocks()->where('type', 'diagram')->sole()->payload['svg'];
    expect($svg)->not->toContain('<script')->toContain('<rect');
});

it('skips diagrams when the level does not allow them', function () {
    // Chapter permits rich_text but not diagram.
    app(ContentWriter::class)->write($this->chapter, $this->chapter->schemaLevel, "Text.\n\n```svg\n<svg viewBox=\"0 0 1 1\"><rect/></svg>\n```");

    expect($this->chapter->blocks()->where('type', 'diagram')->count())->toBe(0)
        ->and($this->chapter->blocks()->where('type', 'rich_text')->count())->toBe(1);
});
