<?php

namespace App\Http\Controllers\Studio;

use App\Assessments\QuestionType;
use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuestionBankController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', QuestionBank::class);

        $user = $request->user();

        $banks = QuestionBank::query()
            ->withCount('questions')
            ->with('course:id,title')
            ->orderBy('name')
            ->get()
            // Course banks are visible only to someone who may edit that course.
            ->filter(fn (QuestionBank $bank) => Gate::allows('view', $bank))
            ->map(fn (QuestionBank $bank) => [
                'id' => $bank->id,
                'name' => $bank->name,
                'question_count' => $bank->questions_count,
                'course' => $bank->course === null ? null : ['id' => $bank->course->id, 'title' => $bank->course->title],
            ])
            ->values();

        return Inertia::render('questions/Index', [
            'banks' => $banks,
            // Course banks the user may attach to: those they hold an editing grant on.
            'courses' => Course::query()
                ->whereIn('id', array_keys($user->grantsByCourse()))
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Course $c) => ['id' => $c->id, 'title' => $c->title]),
            'can' => ['create' => $user->can(Permissions::QUESTION_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::QUESTION_MANAGE), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'course_id' => [
                'nullable', 'uuid',
                // You may only attach a bank to a course you can edit.
                Rule::exists('courses', 'id')->whereIn('id', array_keys($request->user()->grantsByCourse())),
            ],
        ]);

        $bank = new QuestionBank([
            'name' => $data['name'],
            'course_id' => $data['course_id'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Re-check through the policy: proves the grant for a course bank, not
        // just that the course exists.
        Gate::authorize('manage', $bank);
        $bank->save();

        return redirect()
            ->route('studio.question-banks.show', $bank)
            ->with('success', "Created question bank “{$bank->name}”.");
    }

    public function show(Request $request, QuestionBank $bank): Response
    {
        Gate::authorize('view', $bank);

        $bank->load('course:id,title');

        return Inertia::render('questions/Show', [
            'bank' => [
                'id' => $bank->id,
                'name' => $bank->name,
                'course' => $bank->course === null ? null : ['id' => $bank->course->id, 'title' => $bank->course->title],
            ],
            'questions' => $bank->questions()->with('options')->latest()->get()
                ->map(fn (Question $q) => [
                    'id' => $q->id,
                    'type' => $q->type,
                    'stem' => $q->stem,
                    'explanation' => $q->explanation,
                    'points' => (float) $q->default_points,
                    'grading' => $q->grading,
                    'options' => $q->options->map(fn (QuestionOption $o): array => [
                        'text' => $o->body['text'] ?? '',
                        'correct' => $o->is_correct,
                    ])->values()->all(),
                ]),
            'types' => QuestionType::names(),
            'can' => ['manage' => Gate::allows('manage', $bank)],
        ]);
    }
}
