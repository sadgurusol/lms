<?php

namespace App\Services\Publishing;

use App\Ai\EmbeddingsClient;
use App\Jobs\EmbedPublicationJob;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PublishCourse
{
    public function __construct(
        private readonly CourseValidator $validator,
        private readonly SnapshotBuilder $snapshots,
    ) {}

    public function handle(Course $course, User $actor, ?string $changelog = null): CoursePublication
    {
        $publication = DB::transaction(function () use ($course, $actor, $changelog) {
            // Serialise publishes of this course: `number` must be contiguous
            // and monotonic, and two concurrent publishes would race on max().
            $course = Course::lockForUpdate()->findOrFail($course->id);

            if ($errors = $this->validator->errors($course)) {
                throw new PublishBlocked($errors);
            }

            $snapshot = $this->snapshots->build($course);

            $publication = CoursePublication::create([
                'course_id' => $course->id,
                'number' => (int) $course->publications()->max('number') + 1,
                'schema_version_id' => $course->schema_version_id,
                'snapshot' => $snapshot,
                'snapshot_etag' => $this->snapshots->etag($snapshot),
                'media_manifest' => $this->snapshots->mediaManifest($snapshot),
                'lessons_count' => \App\Portal\LessonCounter::count($snapshot['tree'] ?? []),
                'changelog' => $changelog ?? $this->changelogAgainstPrevious($course, $snapshot),
                'published_by' => $actor->id,
            ]);

            // Every deliverable course needs a code — clients map/launch by it.
            // forceFill: markDraftDiverged() must not fire and undo this.
            $course->forceFill([
                'code' => $course->code ?: Course::uniqueCode($course->subject ?: $course->title),
                'latest_publication_id' => $publication->id,
                'workflow_state' => Course::STATE_PUBLISHED,
            ])->save();

            AuditLog::record($actor, 'course.published', $course,
                after: ['publication' => $publication->number],
            );

            return $publication;
        });

        // Dispatch cache warming / notifications *after commit*, or a fast
        // worker reads a publication that does not exist yet.
        // DB::afterCommit(fn () => WarmPublicationCache::dispatch($publication));

        // Embed the content for the AI tutor's retrieval, off the request. The
        // transaction has committed, so the publication is safe to load in the
        // job. Only queued when embeddings are configured, to avoid dead work.
        if (app(EmbeddingsClient::class)->configured()) {
            EmbedPublicationJob::dispatch($publication->id);
        }

        return $publication;
    }

    /**
     * Promote an earlier publication back to current.
     *
     * Nothing is mutated; the old snapshot is still there. Rollback is one
     * UPDATE, and no author's in-flight work is disturbed. This is the
     * strongest argument for the snapshot design.
     */
    public function promote(CoursePublication $publication, User $actor): Course
    {
        return DB::transaction(function () use ($publication, $actor) {
            $course = Course::lockForUpdate()->findOrFail($publication->course_id);

            $previous = $course->latest_publication_id;

            $course->forceFill([
                'latest_publication_id' => $publication->id,
                'workflow_state' => Course::STATE_PUBLISHED,
            ])->save();

            AuditLog::record($actor, 'course.publication_promoted', $course,
                before: ['publication_id' => $previous],
                after: ['publication_id' => $publication->id, 'number' => $publication->number],
            );

            return $course->refresh();
        });
    }

    /**
     * Start a new version of a published course.
     *
     * There is nothing to copy: the draft tree already equals the live snapshot
     * (a published course is read-only, so it cannot have diverged). Reopening it
     * for editing is a single state change — draft again — while the publication
     * learners read stays frozen. The next publish becomes version N+1.
     */
    public function revise(Course $course, User $actor): Course
    {
        return DB::transaction(function () use ($course, $actor) {
            $course = Course::lockForUpdate()->findOrFail($course->id);

            if ($course->workflow_state !== Course::STATE_PUBLISHED) {
                throw new RuntimeException('Only a published course can be revised into a new version.');
            }

            $course->update(['workflow_state' => Course::STATE_DRAFT]);

            AuditLog::record($actor, 'course.revision_started', $course,
                after: ['from_publication_id' => $course->latest_publication_id],
            );

            return $course->refresh();
        });
    }

    /**
     * Diff this snapshot against the previous one by node id. Computed at
     * publish, not on read — snapshot N-1 may be archived to object storage by
     * the time anyone asks.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function changelogAgainstPrevious(Course $course, array $snapshot): string
    {
        $previous = $course->publications()->orderByDesc('number')->first();

        if ($previous === null) {
            return 'Initial publication.';
        }

        $before = $this->flattenNodeIds($previous->snapshot['tree']);
        $after = $this->flattenNodeIds($snapshot['tree']);

        $added = count(array_diff($after, $before));
        $removed = count(array_diff($before, $after));

        return match (true) {
            $added === 0 && $removed === 0 => 'Content updated.',
            default => trim(sprintf(
                '%s%s',
                $added ? "{$added} node(s) added. " : '',
                $removed ? "{$removed} node(s) removed." : '',
            )),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $branch
     * @return list<string>
     */
    private function flattenNodeIds(array $branch): array
    {
        $ids = [];

        foreach ($branch as $node) {
            $ids[] = $node['id'];
            $ids = [...$ids, ...$this->flattenNodeIds($node['children'])];
        }

        return $ids;
    }
}
