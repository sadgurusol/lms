<?php

use App\Authorization\Roles;
use App\Jobs\GenerateCourseJob;
use App\Models\Course;
use App\Models\CourseGeneration;
use App\Services\Generation\BlueprintGenerator;
use App\Services\Generation\CourseBuilder;
use App\Services\Generation\GenerationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->version = publish(textbookSchema());
    $this->author = staff(Roles::CONTENT_AUTHOR);
    Sleep::fake(); // don't wait out retry backoff on faked failures
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

    // Phase 1 returns structure only (no content); phase 2 returns plain teaching
    // text per content-bearing node. Distinguish the two by the request body.
    $structure = ['nodes' => [
        ['level' => 'Part', 'title' => 'Part One', 'children' => [
            ['level' => 'Chapter', 'title' => 'Cells', 'children' => [
                ['level' => 'Topic', 'title' => 'The cell'],
            ]],
        ]],
    ]];
    Http::fake(fn ($request) => str_contains($request->body(), 'course STRUCTURE')
        ? Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($structure)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 500, 'output_tokens' => 800],
        ])
        : Http::response([
            'content' => [['type' => 'text', 'text' => 'Cells are the basic unit of life.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 200],
        ]));

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
        // Outline (800) + one content call per content-bearing node (Chapter, Topic).
        ->and($generation->output_tokens)->toBe(800 + 200 + 200);

    $course = $generation->course;
    expect($course->title)->toBe('NEET Biology')
        ->and($course->nodes()->count())->toBe(3);

    $topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->sole();
    expect($topic->blocks()->where('type', 'rich_text')->exists())->toBeTrue();
});

it('writes a placeholder and still completes when a topic content call fails', function () {
    config(['services.anthropic.key' => 'test-key']);

    $structure = ['nodes' => [
        ['level' => 'Part', 'title' => 'P', 'children' => [
            ['level' => 'Chapter', 'title' => 'C'],
        ]],
    ]];
    // Outline succeeds; every content call errors (500). The run must still finish.
    Http::fake(fn ($request) => str_contains($request->body(), 'course STRUCTURE')
        ? Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($structure)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ])
        : Http::response(['error' => 'boom'], 500));

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Resilient',
        'source_type' => 'brief',
        'brief' => 'x',
        'status' => CourseGeneration::PENDING,
    ]);

    (new GenerateCourseJob($generation->id))->handle(app(BlueprintGenerator::class), app(CourseBuilder::class));

    $generation->refresh();
    expect($generation->status)->toBe(CourseGeneration::COMPLETED);

    $chapter = $generation->course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->sole();
    $body = $chapter->blocks()->where('type', 'rich_text')->sole()->payload['body'];
    expect($body[0]['children'][0]['text'])->toContain('could not be generated');
});

it('resumes an already-built course without rebuilding it', function () {
    config(['services.anthropic.key' => 'test-key']);
    // Only content calls should happen on resume — no new outline.
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Real teaching text.']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
    ])]);

    // Pre-build a structure-only course, as the orchestrator would have on a run
    // that then failed part-way through the content chain.
    $structure = ['nodes' => [['level' => 'Part', 'title' => 'P', 'children' => [
        ['level' => 'Chapter', 'title' => 'C'],
    ]]]];
    $course = app(CourseBuilder::class)->build($structure, $this->version, 'Resume', $this->author);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Resume',
        'source_type' => 'brief',
        'brief' => 'x',
        'course_id' => $course->id,
        'status' => CourseGeneration::PENDING,
    ]);

    $before = Course::count();
    (new GenerateCourseJob($generation->id))->handle(app(BlueprintGenerator::class), app(CourseBuilder::class));

    $generation->refresh();
    expect($generation->status)->toBe(CourseGeneration::COMPLETED)
        ->and($generation->course_id)->toBe($course->id)
        ->and(Course::count())->toBe($before); // reused, not rebuilt

    // No outline request was made on resume; the chapter got real content.
    expect(Http::recorded()->every(fn ($pair) => ! str_contains($pair[0]->body(), 'course STRUCTURE')))->toBeTrue();
    $chapter = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->sole();
    expect($chapter->blocks()->where('type', 'rich_text')->sole()->payload['body'][0]['children'][0]['text'])
        ->toBe('Real teaching text.');
});

it('shows the generation settings with the base prompts', function () {
    $this->actingAs($this->author)
        ->get('/studio/generate/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generate/Settings')
            ->where('contentInstructions', '')
            ->where('baseContentPrompt', fn (string $v) => str_contains($v, 'writing the lesson')));
});

it('refuses generation settings to a user without course.create', function () {
    $this->actingAs(staff(Roles::CONTENT_REVIEWER))
        ->get('/studio/generate/settings')
        ->assertForbidden();
});

it('saves admin guidance and appends it to the content prompt', function () {
    $this->actingAs($this->author)
        ->from('/studio/generate/settings')
        ->post('/studio/generate/settings', [
            'contentInstructions' => 'Always include an SVG-style described diagram.',
            'outlineInstructions' => '',
        ])
        ->assertSessionHas('success');

    $prompt = app(GenerationSettings::class)->contentPrompt();
    expect($prompt)->toContain('Always include an SVG-style described diagram.')
        ->and($prompt)->toContain('writing the lesson'); // base is still there
});

it('reports live topic progress for a running generation', function () {
    // One chapter has content, one does not → 1 of 2 topics done.
    $structure = ['nodes' => [['level' => 'Part', 'title' => 'P', 'children' => [
        ['level' => 'Chapter', 'title' => 'C', 'content' => 'Already written.'],
        ['level' => 'Chapter', 'title' => 'D'],
    ]]]];
    $course = app(CourseBuilder::class)->build($structure, $this->version, 'Prog', $this->author);

    $generation = CourseGeneration::create([
        'requested_by' => $this->author->id,
        'schema_version_id' => $this->version->id,
        'name' => 'Prog',
        'source_type' => 'brief',
        'brief' => 'x',
        'course_id' => $course->id,
        'status' => CourseGeneration::PROCESSING,
    ]);

    $this->actingAs($this->author)
        ->get('/studio/generate')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.0.progress.done', 1)
            ->where('generations.0.progress.total', 2));
});

it('recovers an outline whose JSON has raw newlines', function () {
    config(['services.anthropic.key' => 'test-key']);

    // A raw newline inside a "summary" string — invalid JSON per spec, but models
    // emit it. The parser must recover instead of failing the outline.
    $json = "{\"nodes\":[{\"level\":\"Part\",\"title\":\"P\",\"summary\":\"Line one.\nLine two.\"}]}";

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
        ->and($generation->course->nodes()->count())->toBe(1);
});

it('fails clearly when the outline is truncated at the token ceiling', function () {
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
        ->and($generation->error)->toContain('sections');
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
