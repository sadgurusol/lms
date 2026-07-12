<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Services\Tree\CourseTree;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Structural edits to a course's draft tree. Every action is gated by
 * CoursePolicy::update — a node has no authority of its own, it borrows the
 * course's — and legality is enforced by database triggers, which this
 * controller only translates.
 */
class CourseNodeController extends Controller
{
    public function store(Request $request, Course $course, CourseTree $tree): RedirectResponse
    {
        Gate::authorize('update', $course);
        abort_unless($request->user()->can(Permissions::NODE_CREATE), 403);
        $this->assertEditable($course);

        $data = $request->validate([
            'parent_id' => [
                'present', 'nullable', 'uuid',
                Rule::exists('course_nodes', 'id')->where('course_id', $course->id),
            ],
            'schema_level_id' => [
                'required', 'uuid',
                // The level must belong to this course's schema version. The
                // trigger enforces it too; naming the field keeps the error
                // beside the input rather than in a flash banner.
                Rule::exists('schema_levels', 'id')
                    ->where('schema_version_id', $course->schema_version_id),
            ],
            'title' => ['required', 'string', 'max:200'],
            'after_node_id' => ['nullable', 'uuid'],
        ]);

        $parent = $data['parent_id'] === null
            ? null
            : CourseNode::where('course_id', $course->id)->findOrFail($data['parent_id']);

        $level = SchemaLevel::findOrFail($data['schema_level_id']);

        try {
            // A given position places the node after that sibling; none means
            // append to the end, which is what the "add" button intends.
            $data['after_node_id'] ?? null
                ? $tree->createNode($course, $level, $data['title'], $parent, $data['after_node_id'])
                : $tree->appendNode($course, $level, $data['title'], $parent);
        } catch (QueryException $e) {
            // QueryException extends RuntimeException, so it must be caught first
            // — otherwise the capacity RuntimeException clause below swallows a
            // trigger violation and leaks the raw SQL to the author.
            return back()->with('error', $this->humanise($e));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Added “{$data['title']}”.");
    }

    public function update(Request $request, CourseNode $node, CourseTree $tree): RedirectResponse
    {
        Gate::authorize('update', $node);
        $this->assertEditable($node->course);

        $data = $request->validate(['title' => ['required', 'string', 'max:200']]);

        $tree->renameNode($node, $data['title']);

        return back()->with('success', 'Renamed.');
    }

    /** Reorder a node among its current siblings, or move it under a new parent. */
    public function move(Request $request, CourseNode $node, CourseTree $tree): RedirectResponse
    {
        Gate::authorize('move', $node);
        $this->assertEditable($node->course);

        $data = $request->validate([
            'after_node_id' => ['nullable', 'uuid'],
        ]);

        try {
            $tree->reorderNode($node, $data['after_node_id'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reordered.');
    }

    public function destroy(Request $request, CourseNode $node, CourseTree $tree): RedirectResponse
    {
        Gate::authorize('delete', $node);
        $this->assertEditable($node->course);

        $title = $node->title;
        $tree->deleteNode($node);

        return back()->with('success', "Removed “{$title}” and everything beneath it.");
    }

    /**
     * A published course is a frozen snapshot; its structure cannot change until
     * it is revised into a new draft. This holds for everyone, admins included,
     * so it lives beside the permission check rather than inside the policy the
     * admin bypass skips.
     */
    private function assertEditable(Course $course): void
    {
        abort_unless(
            $course->isEditable(),
            403,
            'This course is published. Start a new version to edit it.',
        );
    }

    private function humanise(QueryException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'requires a parent node') => 'That level cannot sit at the top of the course.',
            str_contains($message, 'cannot be nested under a node') => 'A top-level section cannot be placed inside another node.',
            str_contains($message, 'may not nest under') => 'That level is not allowed directly inside this node.',
            default => 'The database refused that change.',
        };
    }
}
