<?php

use App\Authorization\Roles;
use App\Jobs\GenerateCourseJob;
use App\Models\CourseGeneration;
use App\Services\Generation\BlueprintGenerator;
use App\Services\Generation\CourseBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->version = publish(textbookSchema());
    $this->author = staff(Roles::CONTENT_AUTHOR);
});

it('queues a generation from a brief', function () {
    Queue::fake();

    $this->actingAs($this->author)
        ->from('/studio/generate')
        ->post('/studio/generate', [
            'name' => 'NEET Biology',
            'schema_version_id' => $this->version->id,
            'source_type' => 'brief',
            'brief' => 'A full NEET Biology course for Class 11, aligned to the NTA syllabus.',
        ])
        ->assertSessionHas('success');

    $generation = CourseGeneration::sole();
    expect($generation->status)->toBe(CourseGeneration::PENDING)
        ->and($generation->source_type)->toBe('brief');
    Queue::assertPushed(GenerateCourseJob::class);
});

it('queues a generation from a PDF upload', function () {
    Queue::fake();
    Storage::fake(config('filesystems.default'));

    $this->actingAs($this->author)
        ->post('/studio/generate', [
            'name' => 'Physics',
            'schema_version_id' => $this->version->id,
            'source_type' => 'pdf',
            'pdf' => UploadedFile::fake()->create('textbook.pdf', 200, 'application/pdf'),
        ])
        ->assertSessionHas('success');

    expect(CourseGeneration::sole()->pdf_path)->not->toBeNull();
});

it('validates the brief is present for a brief generation', function () {
    $this->actingAs($this->author)
        ->from('/studio/generate')
        ->post('/studio/generate', [
            'name' => 'X',
            'schema_version_id' => $this->version->id,
            'source_type' => 'brief',
        ])
        ->assertSessionHasErrors('brief');
});

it('refuses generation to a user without course.create', function () {
    $this->actingAs(staff(Roles::CONTENT_REVIEWER))
        ->get('/studio/generate')
        ->assertForbidden();
});

it('runs the job end to end, producing a draft course', function () {
    config(['services.anthropic.key' => 'test-key']);

    $blueprint = ['nodes' => [
        ['level' => 'Part', 'title' => 'Part One', 'children' => [
            ['level' => 'Chapter', 'title' => 'Cells', 'children' => [
                ['level' => 'Topic', 'title' => 'The cell', 'content' => 'Cells are the basic unit of life.'],
            ]],
        ]],
    ]];
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($blueprint)]],
            'usage' => ['input_tokens' => 500, 'output_tokens' => 800],
        ]),
    ]);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'NEET Biology',
        'source_type' => 'brief',
        'brief' => 'NEET Biology, Class 11.',
        'status' => CourseGeneration::PENDING,
    ]);

    (new GenerateCourseJob($generation->id))->handle(
        app(BlueprintGenerator::class),
        app(CourseBuilder::class),
    );

    $generation->refresh();
    expect($generation->status)->toBe(CourseGeneration::COMPLETED)
        ->and($generation->course_id)->not->toBeNull()
        ->and($generation->output_tokens)->toBe(800);

    $course = $generation->course;
    expect($course->title)->toBe('NEET Biology')
        ->and($course->nodes()->count())->toBe(3);
});

it('marks the generation failed when the AI returns nonsense', function () {
    config(['services.anthropic.key' => 'test-key']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'I could not do that.']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Broken',
        'source_type' => 'brief',
        'brief' => 'x',
        'status' => CourseGeneration::PENDING,
    ]);

    (new GenerateCourseJob($generation->id))->handle(
        app(BlueprintGenerator::class),
        app(CourseBuilder::class),
    );

    expect($generation->refresh()->status)->toBe(CourseGeneration::FAILED)
        ->and($generation->error)->not->toBeNull();
});
