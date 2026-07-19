<?php

use App\Authorization\Roles;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Services\Generation\CourseBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->version = publish(textbookSchema());
    $this->author = staff(Roles::CONTENT_AUTHOR);
});

it('builds a draft course tree with content from a blueprint', function () {
    $blueprint = ['nodes' => [
        ['level' => 'Part', 'title' => 'Part One', 'summary' => 'The basics', 'children' => [
            ['level' => 'Chapter', 'title' => 'Chapter One', 'children' => [
                ['level' => 'Topic', 'title' => 'Topic One', 'content' => "A first paragraph.\n\n## A subheading\n\n- point one\n- point two"],
            ]],
        ]],
    ]];

    $course = app(CourseBuilder::class)->build($blueprint, $this->version, 'Generated Course', $this->author);

    expect($course->title)->toBe('Generated Course')
        ->and($course->workflow_state)->toBe(Course::STATE_DRAFT);

    $part = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Part'))->sole();
    $chapter = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->sole();
    $topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->sole();

    expect($part->title)->toBe('Part One')
        ->and($part->summary)->toBe('The basics')
        ->and($chapter->parent_id)->toBe($part->id)
        ->and($topic->parent_id)->toBe($chapter->id);

    // The topic carries a rich-text block with the parsed content.
    $block = ContentBlock::where('course_node_id', $topic->id)->where('type', 'rich_text')->sole();
    $body = $block->payload['body'];
    expect($body[0]['children'][0]['text'])->toBe('A first paragraph.')
        ->and($body[1]['style'])->toBe('h3')
        ->and($body[2]['listItem'])->toBe('bullet');
});

it('skips nodes that name an unknown level rather than failing', function () {
    $blueprint = ['nodes' => [
        ['level' => 'Module', 'title' => 'Not a real level'], // unknown → skipped
        ['level' => 'Part', 'title' => 'Real Part'],
    ]];

    $course = app(CourseBuilder::class)->build($blueprint, $this->version, 'Partial', $this->author);

    expect($course->nodes()->count())->toBe(1)
        ->and($course->nodes()->sole()->title)->toBe('Real Part');
});

it('fails when nothing in the blueprint matches the schema', function () {
    $blueprint = ['nodes' => [['level' => 'Module', 'title' => 'Nope']]];

    expect(fn () => app(CourseBuilder::class)->build($blueprint, $this->version, 'Empty', $this->author))
        ->toThrow(RuntimeException::class, 'did not match the schema');
});
