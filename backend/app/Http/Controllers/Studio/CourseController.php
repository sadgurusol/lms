<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Services\Courses\CreateCourse;
use App\Services\Publishing\SnapshotBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless(
            $user->can(Permissions::COURSE_VIEW_ANY) || $user->can(Permissions::COURSE_VIEW_GRANTED),
            403,
        );

        $courses = Course::query()
            ->with('schemaVersion.courseSchema', 'latestPublication')
            ->withCount('nodes')
            // An author sees the courses they hold a grant on, and no others.
            // `course.view.any` is the ops/admin bypass, and it is the only one.
            ->unless(
                $user->can(Permissions::COURSE_VIEW_ANY),
                fn ($query) => $query->whereIn('id', array_keys($user->grantsByCourse())),
            )
            ->orderBy('title')
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'code' => $course->code,
                'subject' => $course->subject,
                'category' => $course->category,
                'grade_band' => $course->grade_band,
                'language' => $course->language,
                'workflow_state' => $course->workflow_state,
                'node_count' => $course->nodes_count,
                'published_number' => $course->latestPublication?->number,
                // A draft is "pending" when the course has been published before
                // but is not currently published — i.e. an edited next version.
                'has_pending_draft' => $course->latest_publication_id !== null
                    && $course->workflow_state !== Course::STATE_PUBLISHED,
                'schema' => [
                    'name' => $course->schemaVersion->courseSchema->name,
                    'version' => $course->schemaVersion->version,
                ],
            ]);

        return Inertia::render('courses/Index', [
            'courses' => $courses,
            // Only published versions may be bound. A draft's levels still move.
            'schema_versions' => $this->publishableVersions(),
            'can' => [
                'create' => $user->can(Permissions::COURSE_CREATE),
            ],
        ]);
    }

    public function show(Request $request, Course $course): Response
    {
        Gate::authorize('view', $course);

        $course->load('schemaVersion.courseSchema', 'latestPublication');
        $levels = $course->schemaVersion->levels()->get();

        // Editable needs both the authority (permission + grant, or admin) and an
        // editable state: a published course is a frozen snapshot, read-only until
        // revised into a new draft.
        $mayEdit = Gate::allows('update', $course);
        $editable = $mayEdit && $course->isEditable();

        $nodes = $course->nodes()->with('schemaLevel')->withCount('blocks', 'assessments')
            // Read-only: the content is viewed inline in the tree, so ship it with
            // the tree. When editable it lives on its own editor page instead.
            ->when(! $editable, fn ($q) => $q->with('blocks'))
            ->get();

        return Inertia::render('courses/Show', [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'code' => $course->code,
                'subject' => $course->subject,
                'category' => $course->category,
                'grade_band' => $course->grade_band,
                'language' => $course->language,
                'workflow_state' => $course->workflow_state,
                // The version learners currently see, if any.
                'published_number' => $course->latestPublication?->number,
                'is_published' => $course->latestPublication !== null,
                // Public-portal gating (settable once published).
                'visibility' => $course->visibility,
                'free_preview_lessons' => $course->free_preview_lessons,
                'schema' => [
                    'name' => $course->schemaVersion->courseSchema->name,
                    'version' => $course->schemaVersion->version,
                ],
            ],
            // The tree, nested, siblings in sort_key order.
            'tree' => $this->buildBranch($nodes, $levels, null, null),
            // What may be added at the top of the course.
            'root_levels' => $this->addableLevels($nodes, $levels, null, null),
            'can' => [
                'edit' => $editable,
                // A published course can be reopened as a new draft version.
                'revise' => $mayEdit && $course->workflow_state === Course::STATE_PUBLISHED,
                // Only a never-published draft may be deleted outright.
                'delete' => Gate::allows('delete', $course)
                    && $course->workflow_state === Course::STATE_DRAFT
                    && $course->latestPublication === null,
            ],
        ]);
    }

    /**
     * A learner's-eye view of the *draft* course, built from the very structure
     * that ships to clients (SnapshotBuilder), so the preview cannot drift from
     * what publishing will produce. No entitlement or publication is involved —
     * this is the author looking at their own work in progress.
     */
    public function preview(Request $request, Course $course, SnapshotBuilder $builder): Response
    {
        Gate::authorize('view', $course);

        return Inertia::render('courses/Preview', [
            'snapshot' => $builder->build($course),
            'context' => [
                'kind' => 'draft',
                'version' => $course->latestPublication()->value('number'),
                'workflow_state' => $course->workflow_state,
            ],
        ]);
    }

    /**
     * The published version, exactly as learners read it — rendered from the
     * immutable publication snapshot, never from the draft tree. This is the
     * "what is live right now" view, read-only and unaffected by any edit in
     * progress on the draft.
     */
    public function published(Request $request, Course $course): Response
    {
        Gate::authorize('view', $course);

        $course->load('latestPublication');
        $publication = $course->latestPublication;

        abort_if($publication === null, 404, 'This course has never been published.');

        return Inertia::render('courses/Preview', [
            // The snapshot was frozen at publish time; it is the source of truth.
            'snapshot' => $publication->snapshot,
            'context' => [
                'kind' => 'published',
                'version' => $publication->number,
                'workflow_state' => $course->workflow_state,
            ],
        ]);
    }

    public function store(Request $request, CreateCourse $creator): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::COURSE_CREATE), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('courses', 'code')],
            'subject' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', Rule::in(\App\Portal\Category::values())],
            'grade_band' => ['nullable', 'string', 'max:40'],
            'language' => ['required', 'string', 'max:10'],
            'schema_version_id' => [
                'required', 'uuid',
                // Refuse a draft version here, not just in the service. A client
                // that names one is asking for something incoherent, and the
                // answer belongs next to the field that named it.
                Rule::exists('schema_versions', 'id')
                    ->where('status', SchemaVersion::STATUS_PUBLISHED),
            ],
        ]);

        $version = SchemaVersion::findOrFail($data['schema_version_id']);
        unset($data['schema_version_id']);

        try {
            $course = $creator->handle($data, $version, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Created “{$course->title}”. Add its content next.");
    }

    /**
     * Edit a draft course's metadata (title, code, subject, …). The structure —
     * schema version, nodes, blocks — is edited elsewhere; this is only the
     * course's own descriptive fields, and only while it is still editable.
     */
    public function update(Request $request, Course $course): RedirectResponse
    {
        Gate::authorize('update', $course);
        abort_unless(
            $course->isEditable(),
            403,
            'A published course is read-only — revise it into a new draft first.'
        );

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            // Ignore this course's own row so re-saving an unchanged code passes.
            'code' => ['nullable', 'string', 'max:40', Rule::unique('courses', 'code')->ignore($course->id)],
            'subject' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', Rule::in(\App\Portal\Category::values())],
            'grade_band' => ['nullable', 'string', 'max:40'],
            'language' => ['required', 'string', 'max:10'],
        ]);

        $course->update($data);

        return back()->with('success', 'Course details updated.');
    }

    /**
     * Public-portal visibility + free-preview limit. Unlike content, these are a
     * publishing decision, so they're settable even on a published course.
     */
    public function visibility(Request $request, Course $course): RedirectResponse
    {
        Gate::authorize('update', $course);

        $data = $request->validate([
            'visibility' => ['required', Rule::in(Course::VISIBILITIES)],
            'free_preview_lessons' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $course->update($data);

        return back()->with('success', 'Portal visibility updated.');
    }

    /**
     * Delete a draft course. Restricted to a never-published draft: a course with
     * a live publication is being consumed by learners through its snapshots, and
     * anything past draft goes through withdraw/archive instead. Soft-deletes, so
     * it is recoverable and never orphans a snapshot.
     */
    public function destroy(Request $request, Course $course): RedirectResponse
    {
        Gate::authorize('delete', $course);
        abort_unless(
            $course->workflow_state === Course::STATE_DRAFT && $course->latestPublication === null,
            403,
            'Only an unpublished draft course can be deleted.'
        );

        $title = $course->title;
        $course->delete();

        return redirect('/studio/courses')->with('success', "Deleted “{$title}”.");
    }

    /**
     * One level of the tree, nested, with each child's own subtree attached.
     *
     * Built in memory from the full node set so the whole tree costs one query,
     * not one per node. Siblings are ordered by sort_key — path order sorts by
     * id, which is not the authored order.
     *
     * @param  Collection<int, CourseNode>  $nodes
     * @param  Collection<int, SchemaLevel>  $levels
     * @return list<array<string, mixed>>
     */
    private function buildBranch(Collection $nodes, Collection $levels, ?string $parentId, ?string $parentLevelId): array
    {
        return $nodes
            ->where('parent_id', $parentId)
            ->sortBy('sort_key', SORT_STRING)
            ->map(fn (CourseNode $node) => [
                'id' => $node->id,
                'title' => $node->title,
                'level_name' => $node->schemaLevel->name,
                'allows_content' => $node->schemaLevel->allows_content,
                'block_count' => $node->blocks_count,
                'allows_assessment' => $node->schemaLevel->allows_assessment,
                'assessment_count' => $node->assessments_count,
                // Present only in read-only mode (blocks eager-loaded), for the
                // in-place viewer. Empty otherwise — editing uses the block page.
                'blocks' => $node->relationLoaded('blocks')
                    ? $node->blocks->map(fn (ContentBlock $b) => [
                        'id' => $b->id,
                        'type' => $b->type,
                        'payload' => $b->payload,
                    ])->values()->all()
                    : [],
                'children' => $this->buildBranch($nodes, $levels, $node->id, $node->schema_level_id),
                'add_levels' => $this->addableLevels($nodes, $levels, $node->id, $node->schema_level_id),
            ])
            ->values()
            ->all();
    }

    /**
     * The levels a new child may take under a given node (or the root), each
     * with remaining capacity. `remaining` is null when unbounded; the editor
     * disables the button at zero rather than let the create trigger reject it.
     *
     * @param  Collection<int, CourseNode>  $nodes
     * @param  Collection<int, SchemaLevel>  $levels
     * @return list<array<string, mixed>>
     */
    private function addableLevels(Collection $nodes, Collection $levels, ?string $parentNodeId, ?string $parentLevelId): array
    {
        return $levels
            ->where('parent_level_id', $parentLevelId)
            ->sortBy('sort_key', SORT_STRING)
            ->map(function (SchemaLevel $level) use ($nodes, $parentNodeId): array {
                $used = $nodes
                    ->where('parent_id', $parentNodeId)
                    ->where('schema_level_id', $level->id)
                    ->count();

                return [
                    'schema_level_id' => $level->id,
                    'name' => $level->name,
                    'remaining' => $level->max_occurrences === null
                        ? null
                        : max(0, $level->max_occurrences - $used),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The published versions a course may be built on, newest schema first.
     *
     * @return list<array<string, mixed>>
     */
    private function publishableVersions(): array
    {
        return SchemaVersion::query()
            ->where('status', SchemaVersion::STATUS_PUBLISHED)
            // A version whose schema was archived (soft-deleted) has no live
            // parent to name, and a course can no longer be built on it.
            ->whereHas('courseSchema')
            ->with('courseSchema')
            ->get()
            ->sortBy(fn (SchemaVersion $version) => [$version->courseSchema->name, -$version->version])
            ->map(fn (SchemaVersion $version) => [
                'id' => $version->id,
                'label' => "{$version->courseSchema->name} · v{$version->version}",
            ])
            ->values()
            ->all();
    }
}
