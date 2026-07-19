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

it('decodes an outline whose content has raw newlines', function () {
    config(['services.anthropic.key' => 'test-key']);

    // Raw newlines inside the "content" string — invalid JSON per spec, but the
    // model emits it constantly. The parser must recover instead of failing.
    $json = '{"nodes":[{"level":"Part","title":"P","children":['
        .'{"level":"Chapter","title":"C","children":['
        ."{\"level\":\"Topic\",\"title\":\"T\",\"content\":\"Line one.\n\nLine two.\"}]}]}]}";

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $json]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ]),
    ]);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Newlines',
        'source_type' => 'brief',
        'brief' => 'x',
        'status' => CourseGeneration::PENDING,
    ]);

    (new GenerateCourseJob($generation->id))->handle(
        app(BlueprintGenerator::class),
        app(CourseBuilder::class),
    );

    expect($generation->refresh()->status)->toBe(CourseGeneration::COMPLETED)
        ->and($generation->course->nodes()->count())->toBe(3);
});

it('fails clearly when the reply is truncated at the token ceiling', function () {
    config(['services.anthropic.key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"nodes":[{"level":"Part","title":"P","children":[']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 16000],
        ]),
    ]);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Too long',
        'source_type' => 'brief',
        'brief' => 'x',
        'status' => CourseGeneration::PENDING,
    ]);

    (new GenerateCourseJob($generation->id))->handle(
        app(BlueprintGenerator::class),
        app(CourseBuilder::class),
    );

    expect($generation->refresh()->status)->toBe(CourseGeneration::FAILED)
        ->and($generation->error)->toContain('cut off');
});

it('retries a failed generation, re-queuing the job', function () {
    Queue::fake();

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Retry me',
        'source_type' => 'brief',
        'brief' => 'x',
        'status' => CourseGeneration::FAILED,
        'error' => 'The AI outline was not valid JSON.',
    ]);

    $this->actingAs($this->author)
        ->from('/studio/generate')
        ->post("/studio/generate/{$generation->id}/retry")
        ->assertSessionHas('success');

    $generation->refresh();
    expect($generation->status)->toBe(CourseGeneration::PENDING)
        ->and($generation->error)->toBeNull();
    Queue::assertPushed(GenerateCourseJob::class);
});

it('refuses to retry a generation that has not failed', function () {
    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Done',
        'source_type' => 'brief',
        'brief' => 'x',
        'status' => CourseGeneration::COMPLETED,
    ]);

    $this->actingAs($this->author)
        ->post("/studio/generate/{$generation->id}/retry")
        ->assertStatus(422);
});

it('refuses to retry another author\'s generation', function () {
    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Not yours',
        'source_type' => 'brief',
        'brief' => 'x',
        'status' => CourseGeneration::FAILED,
    ]);

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/studio/generate/{$generation->id}/retry")
        ->assertForbidden();
});

it('keeps the PDF on failure so it can be retried', function () {
    config(['services.anthropic.key' => 'test-key']);
    Storage::fake(config('filesystems.default'));
    $path = Storage::disk(config('filesystems.default'))->putFileAs(
        'generations', UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'), 'book.pdf'
    );
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'nope']],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'PDF fail',
        'source_type' => 'pdf',
        'pdf_path' => $path,
        'status' => CourseGeneration::PENDING,
    ]);
    (new GenerateCourseJob($generation->id))->handle(app(BlueprintGenerator::class), app(CourseBuilder::class));

    expect($generation->refresh()->status)->toBe(CourseGeneration::FAILED)
        ->and($generation->pdf_path)->not->toBeNull();
    Storage::disk(config('filesystems.default'))->assertExists($path);
});

it('drops the PDF once the generation succeeds', function () {
    config(['services.anthropic.key' => 'test-key']);
    Storage::fake(config('filesystems.default'));
    $path = Storage::disk(config('filesystems.default'))->putFileAs(
        'generations', UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'), 'book.pdf'
    );
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode(['nodes' => [['level' => 'Part', 'title' => 'P']]])]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'PDF ok',
        'source_type' => 'pdf',
        'pdf_path' => $path,
        'status' => CourseGeneration::PENDING,
    ]);
    (new GenerateCourseJob($generation->id))->handle(app(BlueprintGenerator::class), app(CourseBuilder::class));

    expect($generation->refresh()->status)->toBe(CourseGeneration::COMPLETED)
        ->and($generation->pdf_path)->toBeNull();
    Storage::disk(config('filesystems.default'))->assertMissing($path);
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
