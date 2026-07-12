<?php

namespace App\Http\Controllers\Studio;

use App\Assessments\AssessmentSettings;
use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\CourseNode;
use App\Models\Question;
use App\Services\Assessments\AssessmentEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Building quizzes and tests on a node. Legality of *taking* them lives in the
 * assessment services; this is the assembly surface — which questions, in what
 * order, worth how much, under which rules.
 */
class AssessmentController extends Controller
{
    public function index(Request $request, CourseNode $node): Response
    {
        Gate::authorize('view', $node);
        $node->load('schemaLevel', 'course');

        $editable = $node->schemaLevel->allows_assessment
            && Gate::allows('update', $node->course)
            && $node->course->isEditable()
            && $request->user()->can(Permissions::ASSESSMENT_MANAGE);

        return Inertia::render('assessments/Index', [
            'node' => [
                'id' => $node->id,
                'title' => $node->title,
                'level_name' => $node->schemaLevel->name,
                'allows_assessment' => $node->schemaLevel->allows_assessment,
            ],
            'course' => ['id' => $node->course->id, 'title' => $node->course->title],
            'assessments' => $node->assessments()->withCount('assessmentQuestions')->latest()->get()
                ->map(fn (Assessment $a) => [
                    'id' => $a->id,
                    'kind' => $a->kind,
                    'title' => $a->title,
                    'question_count' => $a->assessment_questions_count,
                    'total_points' => (float) $a->total_points,
                ]),
            'can' => ['manage' => $editable],
        ]);
    }

    public function store(Request $request, CourseNode $node, AssessmentEditor $editor): RedirectResponse
    {
        $this->assertManageNode($request, $node);

        $data = $request->validate([
            'kind' => ['required', Rule::in([Assessment::KIND_QUIZ, Assessment::KIND_TEST])],
            'title' => ['required', 'string', 'max:200'],
        ]);

        $assessment = $editor->create($node, $data['kind'], $data['title']);

        return redirect()
            ->route('studio.assessments.show', $assessment)
            ->with('success', 'Assessment created. Add questions and set the rules.');
    }

    public function show(Request $request, Assessment $assessment): Response
    {
        Gate::authorize('view', $assessment);

        $assessment->load([
            'course',
            'assessmentQuestions.question.options',
        ]);

        $config = $assessment->config();

        return Inertia::render('assessments/Show', [
            'assessment' => [
                'id' => $assessment->id,
                'kind' => $assessment->kind,
                'title' => $assessment->title,
                'total_points' => (float) $assessment->total_points,
                'node_id' => $assessment->course_node_id,
                'settings' => $this->settingsToArray($config),
            ],
            'course' => ['id' => $assessment->course->id, 'title' => $assessment->course->title],
            'questions' => $assessment->assessmentQuestions->map(fn (AssessmentQuestion $aq) => [
                'id' => $aq->id,
                'question_id' => $aq->question_id,
                'type' => $aq->question->type,
                'stem' => $aq->question->stem,
                'points' => (float) $aq->points,
            ])->values(),
            // Questions the assembler may still add: from banks in scope, minus
            // those already on this assessment.
            'available' => $this->availableQuestions($assessment),
            'can' => ['manage' => Gate::allows('manage', $assessment) && $assessment->course->isEditable()],
        ]);
    }

    public function update(Request $request, Assessment $assessment, AssessmentEditor $editor): RedirectResponse
    {
        $this->assertManage($assessment);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'time_limit_s' => ['nullable', 'integer', 'min:10', 'max:86400'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'pass_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shuffle_questions' => ['required', 'boolean'],
            'shuffle_options' => ['required', 'boolean'],
            'show_answers' => ['required', Rule::in([
                AssessmentSettings::SHOW_NEVER,
                AssessmentSettings::SHOW_AFTER_SUBMIT,
                AssessmentSettings::SHOW_AFTER_PASS,
            ])],
            'allow_backtrack' => ['required', 'boolean'],
            'counts_toward_progress' => ['required', 'boolean'],
            'question_pool_size' => ['nullable', 'integer', 'min:1'],
        ]);

        $title = $data['title'];
        unset($data['title']);

        $editor->updateSettings($assessment, $title, $data);

        return back()->with('success', 'Assessment settings saved.');
    }

    public function destroy(Request $request, Assessment $assessment, AssessmentEditor $editor): RedirectResponse
    {
        $this->assertManage($assessment);

        $node = $assessment->course_node_id;
        $editor->delete($assessment);

        return redirect()
            ->route('studio.assessments.index', $node)
            ->with('success', 'Assessment removed.');
    }

    private function assertManageNode(Request $request, CourseNode $node): void
    {
        $node->loadMissing('schemaLevel', 'course');

        abort_unless($request->user()->can(Permissions::ASSESSMENT_MANAGE), 403);
        Gate::authorize('update', $node->course);
        abort_unless($node->schemaLevel->allows_assessment, 403, 'This level does not allow assessments.');
        abort_unless($node->course->isEditable(), 403, 'This course is published. Start a new version to edit it.');
    }

    private function assertManage(Assessment $assessment): void
    {
        Gate::authorize('manage', $assessment);
        abort_unless(
            $assessment->course->isEditable(),
            403,
            'This course is published. Start a new version to edit it.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableQuestions(Assessment $assessment): array
    {
        $used = $assessment->assessmentQuestions->pluck('question_id')->all();

        return Question::query()
            ->whereHas('questionBank', fn ($q) => $q
                // A bank in scope: global, or scoped to this assessment's course.
                // Grouped, or the OR would escape the EXISTS predicate.
                ->where(fn ($b) => $b
                    ->whereNull('course_id')
                    ->orWhere('course_id', $assessment->course_id)))
            ->whereNotIn('id', $used)
            ->with('questionBank:id,name')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Question $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'stem' => $q->stem,
                'default_points' => (float) $q->default_points,
                'bank' => $q->questionBank->name,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function settingsToArray(AssessmentSettings $c): array
    {
        return [
            'time_limit_s' => $c->timeLimitSeconds,
            'max_attempts' => $c->maxAttempts,
            'pass_percentage' => $c->passPercentage,
            'shuffle_questions' => $c->shuffleQuestions,
            'shuffle_options' => $c->shuffleOptions,
            'show_answers' => $c->showAnswers,
            'allow_backtrack' => $c->allowBacktrack,
            'counts_toward_progress' => $c->countsTowardProgress,
            'question_pool_size' => $c->questionPoolSize,
        ];
    }
}
