<?php

use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\ContentBlocks\BlockType;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\CourseGrant;
use App\Models\CourseNode;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use App\Services\Assessments\AssessmentEditor;
use App\Services\Content\BlockEditor;
use App\Services\Publishing\PublishCourse;
use App\Services\Tree\CourseTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * An owned course with a Topic node (Topic allows assessment in the textbook
 * schema), plus a global bank of questions.
 *
 * @return array{CourseNode, User, QuestionBank}
 */
function assessmentFixture(): array
{
    [$course, $partL, $chapterL, $topicL] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    $tree = app(CourseTree::class);
    $part = $tree->appendNode($course, $partL, 'Part');
    $chapter = $tree->appendNode($course, $chapterL, 'Chapter', $part);
    $topic = $tree->appendNode($course, $topicL, 'Topic', $chapter);

    $bank = QuestionBank::factory()->create(['course_id' => null]);

    return [$topic, $author, $bank];
}

function bankQuestion(QuestionBank $bank): Question
{
    return Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => true])
        ->create(['question_bank_id' => $bank->id, 'default_points' => 2]);
}

/*
|--------------------------------------------------------------------------
| Creating assessments
|--------------------------------------------------------------------------
*/

it('creates a quiz on a topic', function () {
    [$node, $author] = assessmentFixture();

    $this->actingAs($author)
        ->post("/studio/course-nodes/{$node->id}/assessments", ['kind' => 'quiz', 'title' => 'Warm-up'])
        ->assertRedirect();

    $a = Assessment::sole();
    expect($a->kind)->toBe('quiz')
        ->and($a->title)->toBe('Warm-up')
        ->and($a->course_node_id)->toBe($node->id);
});

it('refuses an assessment on a level that does not allow it', function () {
    [$topic, $author] = assessmentFixture();
    // The Part level does not allow assessments in the textbook schema.
    $part = $topic->course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Part'))->firstOrFail();

    $this->actingAs($author)
        ->post("/studio/course-nodes/{$part->id}/assessments", ['kind' => 'quiz', 'title' => 'Nope'])
        ->assertForbidden();

    expect(Assessment::count())->toBe(0);
});

it('refuses assessment creation without an editing grant', function () {
    [$node] = assessmentFixture();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->post("/studio/course-nodes/{$node->id}/assessments", ['kind' => 'quiz', 'title' => 'Sneaky'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Assembling questions
|--------------------------------------------------------------------------
*/

it('adds a question and keeps total points in step', function () {
    [$node, $author, $bank] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);
    $q = bankQuestion($bank);

    $this->actingAs($author)
        ->from("/studio/assessments/{$assessment->id}")
        ->post("/studio/assessments/{$assessment->id}/questions", ['question_id' => $q->id])
        ->assertSessionHas('success');

    $assessment->refresh();
    $link = AssessmentQuestion::sole();
    expect($link->question_id)->toBe($q->id)
        // Defaulted from the question's own points.
        ->and((float) $link->points)->toBe(2.0)
        ->and((float) $assessment->total_points)->toBe(2.0);
});

it('overrides points per assessment and re-totals', function () {
    [$node, $author, $bank] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);
    $q = bankQuestion($bank);

    $this->actingAs($author)->post("/studio/assessments/{$assessment->id}/questions", ['question_id' => $q->id, 'points' => 5]);
    $link = AssessmentQuestion::sole();

    $this->actingAs($author)
        ->from("/studio/assessments/{$assessment->id}")
        ->patch("/studio/assessment-questions/{$link->id}", ['points' => 8])
        ->assertSessionHas('success');

    expect((float) $link->fresh()->points)->toBe(8.0)
        ->and((float) $assessment->fresh()->total_points)->toBe(8.0);
});

it('refuses the same question twice', function () {
    [$node, $author, $bank] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);
    $q = bankQuestion($bank);

    $this->actingAs($author)->post("/studio/assessments/{$assessment->id}/questions", ['question_id' => $q->id]);

    $this->actingAs($author)
        ->from("/studio/assessments/{$assessment->id}")
        ->post("/studio/assessments/{$assessment->id}/questions", ['question_id' => $q->id])
        ->assertSessionHas('error');

    expect(AssessmentQuestion::count())->toBe(1);
});

/** A question from another course's bank must not be attachable. */
it('refuses a question from an out-of-scope bank', function () {
    [$node, $author] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);

    [$otherCourse] = textbookCourse();
    $foreignBank = QuestionBank::factory()->create(['course_id' => $otherCourse->id]);
    $foreign = bankQuestion($foreignBank);

    $this->actingAs($author)
        ->post("/studio/assessments/{$assessment->id}/questions", ['question_id' => $foreign->id])
        ->assertForbidden();

    expect(AssessmentQuestion::count())->toBe(0);
});

it('removes a question and re-totals', function () {
    [$node, $author, $bank] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);
    $this->actingAs($author)->post("/studio/assessments/{$assessment->id}/questions", ['question_id' => bankQuestion($bank)->id]);
    $link = AssessmentQuestion::sole();

    $this->actingAs($author)
        ->from("/studio/assessments/{$assessment->id}")
        ->delete("/studio/assessment-questions/{$link->id}")
        ->assertSessionHas('success');

    expect(AssessmentQuestion::count())->toBe(0)
        ->and((float) $assessment->fresh()->total_points)->toBe(0.0);
});

it('reorders questions', function () {
    [$node, $author, $bank] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);
    $editor = app(AssessmentEditor::class);
    $a = $editor->addQuestion($assessment, bankQuestion($bank));
    $b = $editor->addQuestion($assessment, bankQuestion($bank));

    $this->actingAs($author)
        ->from("/studio/assessments/{$assessment->id}")
        ->post("/studio/assessment-questions/{$a->id}/move", ['after_id' => $b->id])
        ->assertSessionHas('success');

    expect(AssessmentQuestion::orderBy('sort_key')->pluck('id')->all())->toBe([$b->id, $a->id]);
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

it('saves assessment settings', function () {
    [$node, $author] = assessmentFixture();
    $assessment = Assessment::factory()->test()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);

    $this->actingAs($author)
        ->from("/studio/assessments/{$assessment->id}")
        ->patch("/studio/assessments/{$assessment->id}", [
            'title' => 'Final Test',
            'time_limit_s' => 600,
            'max_attempts' => 2,
            'pass_percentage' => 60,
            'shuffle_questions' => true,
            'shuffle_options' => false,
            'show_answers' => 'after_pass',
            'allow_backtrack' => false,
            'counts_toward_progress' => true,
            'question_pool_size' => null,
        ])
        ->assertSessionHas('success');

    $config = $assessment->fresh()->config();
    expect($assessment->fresh()->title)->toBe('Final Test')
        ->and($config->timeLimitSeconds)->toBe(600)
        ->and($config->passPercentage)->toBe(60.0)
        ->and($config->allowBacktrack)->toBeFalse();
});

it('rejects an out-of-range pass percentage', function () {
    [$node, $author] = assessmentFixture();
    $assessment = Assessment::factory()->test()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);

    $this->actingAs($author)
        ->from("/studio/assessments/{$assessment->id}")
        ->patch("/studio/assessments/{$assessment->id}", [
            'title' => 'X', 'pass_percentage' => 150,
            'shuffle_questions' => true, 'shuffle_options' => true,
            'show_answers' => 'never', 'allow_backtrack' => true, 'counts_toward_progress' => false,
        ])
        ->assertSessionHasErrors('pass_percentage');
});

/*
|--------------------------------------------------------------------------
| Viewing and the publish lock
|--------------------------------------------------------------------------
*/

it('shows the editor with available bank questions', function () {
    [$node, $author, $bank] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);
    bankQuestion($bank);

    $this->actingAs($author)
        ->get("/studio/assessments/{$assessment->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('assessments/Show')
            ->where('assessment.kind', 'quiz')
            ->has('available', 1)
            ->where('can.manage', true)
        );
});

it('locks assessment editing on a published course', function () {
    [$node, $author, $bank] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);

    // Fill and publish the course.
    $course = $node->course;
    app(BlockEditor::class);
    $chapter = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Chapter'))->firstOrFail();
    app(BlockEditor::class)->append($chapter, BlockType::RichText->value);
    app(BlockEditor::class)->append($node, BlockType::RichText->value);
    app(PublishCourse::class)->handle($course->fresh(), staff(Roles::ADMIN));

    $this->actingAs($author)
        ->post("/studio/assessments/{$assessment->id}/questions", ['question_id' => bankQuestion($bank)->id])
        ->assertForbidden();

    // The editor advertises read-only.
    $this->actingAs($author)
        ->get("/studio/assessments/{$assessment->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false));
});

it('sends a guest away', function () {
    [$node] = assessmentFixture();
    $assessment = Assessment::factory()->quiz()->create(['course_id' => $node->course_id, 'course_node_id' => $node->id]);

    $this->get("/studio/assessments/{$assessment->id}")->assertRedirect('/studio/login');
});
