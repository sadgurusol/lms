<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\CoursePublication;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\Publishing\CourseValidator;
use App\Services\Publishing\Finding;
use App\Services\Publishing\PublishBlocked;
use App\Services\Publishing\PublishCourse;
use App\Services\Review\ReviewWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The review-and-publish surface for one course.
 *
 * Readiness (CourseValidator), the review handshake (ReviewWorkflow) and the
 * publish snapshot (PublishCourse) already exist and are the single source of
 * truth. This controller only exposes them and keeps the two authority rules
 * that matter visible: separation of duties on review, and publish behind its
 * own permission.
 */
class CourseWorkflowController extends Controller
{
    public function show(Request $request, Course $course, CourseValidator $validator): Response
    {
        Gate::authorize('view', $course);

        $findings = $validator->validate($course);
        $errorCount = count(array_filter($findings, fn (Finding $f) => $f->isError()));
        $open = $this->openReview($course);

        return Inertia::render('courses/Review', [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'workflow_state' => $course->workflow_state,
            ],
            'findings' => array_map(fn (Finding $f) => $f->jsonSerialize(), $findings),
            'error_count' => $errorCount,
            'open_review' => $open === null ? null : [
                'id' => $open->id,
                'submitted_by' => User::find($open->submitted_by)?->name,
                'assigned_to' => $open->assigned_to === null ? null : User::find($open->assigned_to)?->name,
                'note' => $open->note,
            ],
            // Only reviewers who may actually review this course, so the assign
            // dropdown never offers someone the review policy would then reject.
            'reviewers' => $this->eligibleReviewers($course),
            'publications' => $course->publications()->latest('number')->get()
                ->map(fn (CoursePublication $p) => [
                    'number' => $p->number,
                    'published_by' => User::find($p->published_by)?->name,
                    'published_at' => $p->published_at->toIso8601String(),
                    'changelog' => $p->changelog,
                    'is_current' => $p->id === $course->latest_publication_id,
                ]),
            'can' => [
                'submit' => Gate::allows('submitForReview', $course),
                'review' => Gate::allows('review', $course),
                'publish' => Gate::allows('publish', $course),
            ],
        ]);
    }

    public function submit(Request $request, Course $course, ReviewWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('submitForReview', $course);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'uuid', Rule::in($this->eligibleReviewerIds($course))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $assignee = $data['assigned_to'] === null ? null : User::findOrFail($data['assigned_to']);

        // Assigning a reviewer also grants them REVIEWER on this course — without
        // it the review policy would deny them. eligibleReviewers already
        // excludes anyone with an editing grant, so this cannot break separation
        // of duties.
        if ($assignee !== null) {
            CourseGrant::firstOrCreate([
                'user_id' => $assignee->id,
                'course_id' => $course->id,
                'role' => CourseGrant::REVIEWER,
            ]);
        }

        try {
            $workflow->submit($course, $request->user(), $assignee, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Submitted for review.');
    }

    public function withdraw(Request $request, Course $course, ReviewWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('submitForReview', $course);

        $open = $this->openReview($course);
        if ($open === null) {
            return back()->with('error', 'There is no open review to withdraw.');
        }

        $workflow->withdraw($open, $request->user());

        return back()->with('success', 'Review withdrawn. The course is a draft again.');
    }

    public function approve(Request $request, Course $course, ReviewWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('review', $course);

        $open = $this->openReview($course);
        if ($open === null) {
            return back()->with('error', 'There is no open review to decide.');
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $workflow->approve($open, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Approved. The course is ready to publish.');
    }

    public function requestChanges(Request $request, Course $course, ReviewWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('review', $course);

        $open = $this->openReview($course);
        if ($open === null) {
            return back()->with('error', 'There is no open review to decide.');
        }

        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $workflow->requestChanges($open, $request->user(), $data['note']);

        return back()->with('success', 'Changes requested. The author has been notified.');
    }

    public function revise(Request $request, Course $course, PublishCourse $publisher): RedirectResponse
    {
        // Same authority as editing (permission + editing grant, or admin). The
        // service enforces that only a published course may be revised.
        Gate::authorize('update', $course);

        try {
            $publisher->revise($course, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('studio.courses.show', $course)
            ->with('success', 'Started a new draft. The published version stays live until you publish again.');
    }

    public function publish(Request $request, Course $course, PublishCourse $publisher): RedirectResponse
    {
        Gate::authorize('publish', $course);

        $data = $request->validate(['changelog' => ['nullable', 'string', 'max:2000']]);

        try {
            $publication = $publisher->handle($course, $request->user(), $data['changelog'] ?? null);
        } catch (PublishBlocked $e) {
            // The readiness panel already lists the findings; say how many.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Published version {$publication->number}. It is now live.");
    }

    private function openReview(Course $course): ?ReviewRequest
    {
        return $course->reviewRequests()
            ->where('state', ReviewRequest::STATE_OPEN)
            ->latest()
            ->first();
    }

    /** @return list<array{id: string, name: string}> */
    private function eligibleReviewers(Course $course): array
    {
        return User::role(Roles::CONTENT_REVIEWER)->orderBy('name')->get()
            // Separation of duties: a reviewer must not also author the course.
            ->reject(fn (User $u) => $u->hasGrantOn($course, CourseGrant::EDITING))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function eligibleReviewerIds(Course $course): array
    {
        return array_column($this->eligibleReviewers($course), 'id');
    }
}
