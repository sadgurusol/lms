<?php

use App\Assessments\Grading\GraderRegistry;
use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\Models\CourseGrant;
use App\Models\Question;
use App\Models\QuestionBank;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array<string, mixed> */
function questionInput(array $overrides = []): array
{
    return [
        'stem' => 'What is 2 + 2?',
        'explanation' => 'Basic arithmetic.',
        'points' => 2,
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Banks
|--------------------------------------------------------------------------
*/

it('lists banks for a question manager', function () {
    QuestionBank::factory()->create(['name' => 'Global Grammar', 'course_id' => null]);

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get('/studio/questions')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('questions/Index')
            ->has('banks', 1)
            ->where('banks.0.name', 'Global Grammar')
            ->where('banks.0.course', null)
            ->where('can.create', true)
        );
});

it('creates a global bank', function () {
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post('/studio/questions', ['name' => 'Poetry', 'course_id' => null])
        ->assertSessionHas('success');

    $bank = QuestionBank::sole();
    expect($bank->name)->toBe('Poetry')
        ->and($bank->isGlobal())->toBeTrue();
});

it('creates a course bank only for a course the author can edit', function () {
    [$course] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    $this->actingAs($author)
        ->post('/studio/questions', ['name' => 'Course Bank', 'course_id' => $course->id])
        ->assertSessionHas('success');

    expect(QuestionBank::sole()->course_id)->toBe($course->id);
});

it('refuses a course bank on a course the author cannot edit', function () {
    [$course] = textbookCourse(); // author holds no grant

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post('/studio/questions', ['name' => 'Sneaky', 'course_id' => $course->id])
        ->assertSessionHasErrors('course_id');

    expect(QuestionBank::count())->toBe(0);
});

it('hides a course bank from an author without a grant on that course', function () {
    [$course] = textbookCourse();
    QuestionBank::factory()->create(['course_id' => $course->id]);

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get('/studio/questions')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('banks', 0));
});

it('refuses banks to a reviewer', function () {
    $this->actingAs(staff(Roles::CONTENT_REVIEWER))
        ->get('/studio/questions')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Authoring questions — and they grade correctly
|--------------------------------------------------------------------------
*/

it('authors an mcq_single that grades its own key', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput([
            'type' => 'mcq_single',
            'options' => [
                ['text' => 'Three', 'correct' => false],
                ['text' => 'Four', 'correct' => true],
                ['text' => 'Five', 'correct' => false],
            ],
        ]))
        ->assertSessionHas('success');

    $question = Question::with('options')->sole();
    expect($question->type)->toBe('mcq_single')
        ->and($question->stem['body'][0]['children'][0]['text'])->toBe('What is 2 + 2?')
        ->and($question->options)->toHaveCount(3);

    // The grader reads exactly what we saved: choose "Four", get full marks.
    $four = $question->options->firstWhere('is_correct', true);
    $result = app(GraderRegistry::class)->for(QuestionType::McqSingle)
        ->grade($question, ['option_id' => $four->id], 2.0);

    expect($result->points)->toBe(2.0);
});

it('rejects an mcq_single without exactly one correct option', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput([
            'type' => 'mcq_single',
            'options' => [
                ['text' => 'A', 'correct' => true],
                ['text' => 'B', 'correct' => true],
            ],
        ]))
        ->assertSessionHasErrors('options');

    expect(Question::count())->toBe(0);
});

it('authors a true_false question', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput([
            'type' => 'true_false',
            'answer' => true,
        ]))
        ->assertSessionHas('success');

    expect(Question::sole()->grading)->toEqual(['answer' => true]);
});

it('authors a numeric question with a tolerance', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput([
            'type' => 'numeric',
            'answer' => '3.14',
            'tolerance' => '0.01',
        ]))
        ->assertSessionHas('success');

    $q = Question::sole();
    $result = app(GraderRegistry::class)->for(QuestionType::Numeric)
        ->grade($q, ['answer' => 3.145], 2.0);

    expect((float) $q->grading['answer'])->toBe(3.14)
        ->and($result->points)->toBe(2.0); // within tolerance
});

it('authors a short_answer question with accepted answers', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput([
            'type' => 'short_answer',
            'accept' => ['Paris', 'paris'],
            'fuzzy' => false,
        ]))
        ->assertSessionHas('success');

    expect(Question::sole()->grading['accept'])->toBe(['Paris', 'paris']);
});

it('requires a max distance when short answer is fuzzy', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput([
            'type' => 'short_answer',
            'accept' => ['Paris'],
            'fuzzy' => true,
        ]))
        ->assertSessionHasErrors('max_distance');
});

it('authors an essay with no answer key', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput(['type' => 'essay']))
        ->assertSessionHas('success');

    expect(Question::sole()->options)->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Editing and deleting
|--------------------------------------------------------------------------
*/

it('edits a question and replaces its options', function () {
    $bank = QuestionBank::factory()->create();
    $question = Question::factory()->ofType(QuestionType::McqSingle)
        ->withOptions([['text' => 'Old A', 'correct' => true], ['text' => 'Old B']])
        ->create(['question_bank_id' => $bank->id]);

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->patch("/studio/questions/{$question->id}", questionInput([
            'options' => [
                ['text' => 'New A', 'correct' => false],
                ['text' => 'New B', 'correct' => true],
            ],
        ]))
        ->assertSessionHas('success');

    $question->refresh()->load('options');
    expect($question->options->pluck('body.text')->all())->toBe(['New A', 'New B'])
        ->and($question->options->firstWhere('is_correct', true)->body['text'])->toBe('New B');
});

it('deletes a question', function () {
    $bank = QuestionBank::factory()->create();
    $question = Question::factory()->create(['question_bank_id' => $bank->id]);

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->from("/studio/question-banks/{$bank->id}")
        ->delete("/studio/questions/{$question->id}")
        ->assertSessionHas('success');

    expect(Question::count())->toBe(0);
});

it('refuses question authoring to a reviewer', function () {
    $bank = QuestionBank::factory()->create();

    $this->actingAs(staff(Roles::CONTENT_REVIEWER))
        ->post("/studio/question-banks/{$bank->id}/questions", questionInput(['type' => 'essay']))
        ->assertForbidden();
});

it('sends a guest away from the banks', function () {
    $this->get('/studio/questions')->assertRedirect('/studio/login');
});
