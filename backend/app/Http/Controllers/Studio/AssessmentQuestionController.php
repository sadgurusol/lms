<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Question;
use App\Services\Assessments\AssessmentEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/** The questions inside one assessment: add, re-mark, reorder, remove. */
class AssessmentQuestionController extends Controller
{
    public function store(Request $request, Assessment $assessment, AssessmentEditor $editor): RedirectResponse
    {
        $this->assertManage($assessment);

        $data = $request->validate([
            // The question must come from a bank in scope for this course.
            'question_id' => [
                'required', 'uuid',
                Rule::exists('questions', 'id')->whereNull('deleted_at'),
            ],
            'points' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
        ]);

        $question = Question::with('questionBank')->findOrFail($data['question_id']);

        abort_unless($this->inScope($assessment, $question), 403, 'That question is not available to this course.');

        try {
            $editor->addQuestion($assessment, $question, isset($data['points']) ? (float) $data['points'] : null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Question added.');
    }

    public function update(Request $request, AssessmentQuestion $assessmentQuestion, AssessmentEditor $editor): RedirectResponse
    {
        $this->assertManage($assessmentQuestion->assessment);

        $data = $request->validate(['points' => ['required', 'numeric', 'min:0.1', 'max:1000']]);

        $editor->setPoints($assessmentQuestion, (float) $data['points']);

        return back()->with('success', 'Marks updated.');
    }

    public function move(Request $request, AssessmentQuestion $assessmentQuestion, AssessmentEditor $editor): RedirectResponse
    {
        $this->assertManage($assessmentQuestion->assessment);

        $data = $request->validate(['after_id' => ['nullable', 'uuid']]);

        try {
            $editor->reorder($assessmentQuestion, $data['after_id'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reordered.');
    }

    public function destroy(Request $request, AssessmentQuestion $assessmentQuestion, AssessmentEditor $editor): RedirectResponse
    {
        $this->assertManage($assessmentQuestion->assessment);

        $editor->removeQuestion($assessmentQuestion);

        return back()->with('success', 'Question removed.');
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

    private function inScope(Assessment $assessment, Question $question): bool
    {
        $bank = $question->questionBank;

        return $bank->course_id === null || $bank->course_id === $assessment->course_id;
    }
}
