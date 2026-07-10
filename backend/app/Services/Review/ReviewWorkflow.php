<?php

namespace App\Services\Review;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\ReviewComment;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * draft ──submit──► in_review ──┬── approve ─────────► approved ──publish──► published
 *                               └── request_changes ─► changes_requested ──┐
 *                                                                          │
 *                                        edit ────────────────────► draft ◄┘
 *
 * The draft tree is deliberately NOT frozen during review. Freezing it causes
 * more pain than it prevents: authors fix typos the reviewer just flagged.
 */
final class ReviewWorkflow
{
    public function submit(Course $course, User $author, ?User $assignee = null, ?string $note = null): ReviewRequest
    {
        return DB::transaction(function () use ($course, $author, $assignee, $note) {
            $course = Course::lockForUpdate()->findOrFail($course->id);

            if (! in_array($course->workflow_state, [
                Course::STATE_DRAFT, Course::STATE_CHANGES_REQUESTED,
            ], true)) {
                throw new RuntimeException("A course in [{$course->workflow_state}] cannot be submitted for review.");
            }

            try {
                $request = ReviewRequest::create([
                    'course_id' => $course->id,
                    'submitted_by' => $author->id,
                    'assigned_to' => $assignee?->id,
                    'state' => ReviewRequest::STATE_OPEN,
                    'note' => $note,
                ]);
            } catch (QueryException $e) {
                // The partial unique index caught a double submission race.
                if (str_contains($e->getMessage(), 'one_open_review_per_course')) {
                    throw new RuntimeException('This course is already awaiting review.');
                }

                throw $e;
            }

            $course->update(['workflow_state' => Course::STATE_IN_REVIEW]);

            AuditLog::record($author, 'course.submitted_for_review', $course,
                after: ['review_request_id' => $request->id],
            );

            return $request;
        });
    }

    public function approve(ReviewRequest $request, User $reviewer, ?string $note = null): ReviewRequest
    {
        return $this->decide($request, $reviewer, ReviewRequest::STATE_APPROVED, Course::STATE_APPROVED, $note);
    }

    public function requestChanges(ReviewRequest $request, User $reviewer, string $note): ReviewRequest
    {
        return $this->decide(
            $request, $reviewer,
            ReviewRequest::STATE_CHANGES_REQUESTED, Course::STATE_CHANGES_REQUESTED,
            $note,
        );
    }

    public function withdraw(ReviewRequest $request, User $author): ReviewRequest
    {
        return DB::transaction(function () use ($request, $author) {
            $request = ReviewRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertOpen($request);

            $request->update([
                'state' => ReviewRequest::STATE_WITHDRAWN,
                'decided_at' => now(),
                'decided_by' => $author->id,
            ]);

            $request->course->update(['workflow_state' => Course::STATE_DRAFT]);

            return $request->refresh();
        });
    }

    public function comment(
        ReviewRequest $request,
        User $author,
        string $body,
        string $anchorType = ReviewComment::ANCHOR_COURSE,
        ?string $anchorId = null,
        ?string $parentCommentId = null,
    ): ReviewComment {
        return ReviewComment::create([
            'review_request_id' => $request->id,
            'parent_comment_id' => $parentCommentId,
            'author_id' => $author->id,
            'body' => $body,
            'anchor_type' => $anchorType,
            'anchor_id' => $anchorId,
        ]);
    }

    public function resolve(ReviewComment $comment, User $user): ReviewComment
    {
        $comment->update(['resolved_at' => now(), 'resolved_by' => $user->id]);

        return $comment->refresh();
    }

    private function decide(
        ReviewRequest $request,
        User $reviewer,
        string $requestState,
        string $courseState,
        ?string $note,
    ): ReviewRequest {
        return DB::transaction(function () use ($request, $reviewer, $requestState, $courseState, $note) {
            $request = ReviewRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertOpen($request);

            $request->update([
                'state' => $requestState,
                'decided_at' => now(),
                'decided_by' => $reviewer->id,
                'decision_note' => $note,
            ]);

            $request->course->update(['workflow_state' => $courseState]);

            AuditLog::record($reviewer, "course.review_{$requestState}", $request->course,
                after: ['review_request_id' => $request->id, 'note' => $note],
            );

            return $request->refresh();
        });
    }

    private function assertOpen(ReviewRequest $request): void
    {
        if (! $request->isOpen()) {
            throw new RuntimeException("Review request {$request->id} is already {$request->state}.");
        }
    }
}
