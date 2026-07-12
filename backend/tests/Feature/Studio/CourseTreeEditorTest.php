<?php

use App\Authorization\Roles;
use App\Models\Course;
use App\Models\CourseGrant;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Models\User;
use App\Services\Tree\CourseTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A published-schema course an author owns, ready to edit.
 *
 * @return array{Course, User, array<string, SchemaLevel>}
 */
function ownedCourse(): array
{
    [$course, $part, $chapter, $topic] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    return [$course, $author, ['Part' => $part, 'Chapter' => $chapter, 'Topic' => $topic]];
}

/*
|--------------------------------------------------------------------------
| Viewing the tree
|--------------------------------------------------------------------------
*/

it('renders an empty course with only its root add options', function () {
    [$course, $author, $levels] = ownedCourse();

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Show')
            ->where('course.title', $course->title)
            ->has('tree', 0)
            ->has('root_levels', 1)
            ->where('root_levels.0.schema_level_id', $levels['Part']->id)
            ->where('root_levels.0.name', 'Part')
            ->where('can.edit', true)
            // Never published, so no "learners see v_" banner.
            ->where('course.published_number', null)
        );
});

it('nests the tree and reports block counts', function () {
    [$course, $author, $levels] = ownedCourse();
    $tree = app(CourseTree::class);

    $part = $tree->createNode($course, $levels['Part'], 'Part One');
    $chapter = $tree->createNode($course, $levels['Chapter'], 'Chapter One', $part);
    $tree->createNode($course, $levels['Topic'], 'Topic One', $chapter);

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tree', 1)
            ->where('tree.0.title', 'Part One')
            ->where('tree.0.level_name', 'Part')
            ->where('tree.0.allows_content', false)
            ->has('tree.0.children', 1)
            ->where('tree.0.children.0.title', 'Chapter One')
            ->where('tree.0.children.0.allows_content', true)
            ->where('tree.0.children.0.block_count', 0)
            ->has('tree.0.children.0.children', 1)
            ->where('tree.0.children.0.children.0.title', 'Topic One')
        );
});

it('orders siblings by sort key, not by id', function () {
    [$course, $author, $levels] = ownedCourse();
    $tree = app(CourseTree::class);

    // Insert C, then A before it, then B between: sort keys, not creation order.
    $c = $tree->createNode($course, $levels['Part'], 'C');
    $tree->createNode($course, $levels['Part'], 'A');
    $tree->createNode($course, $levels['Part'], 'B', null, null);
    // Put A first, then B, then C by reordering.
    $a = CourseNode::where('title', 'A')->sole();
    $b = CourseNode::where('title', 'B')->sole();
    $tree->reorderNode($a, null);
    $tree->reorderNode($b, $a->id);
    $tree->reorderNode($c, $b->id);

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tree.0.title', 'A')
            ->where('tree.1.title', 'B')
            ->where('tree.2.title', 'C')
        );
});

/*
|--------------------------------------------------------------------------
| Adding nodes
|--------------------------------------------------------------------------
*/

it('adds a root node', function () {
    [$course, $author, $levels] = ownedCourse();

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/courses/{$course->id}/nodes", [
            'parent_id' => null,
            'schema_level_id' => $levels['Part']->id,
            'title' => 'Part One',
            'after_node_id' => null,
        ])
        ->assertSessionHas('success');

    $node = CourseNode::sole();
    expect($node->title)->toBe('Part One')
        ->and($node->parent_id)->toBeNull()
        ->and($node->depth)->toBe(0);
});

/**
 * Adding without a position appends. This is the one that bit a real course:
 * head-insertion made every new Unit jump above the last, so Unit 2 rendered
 * above Unit 1 and the whole list looked shuffled.
 */
it('appends new siblings in creation order', function () {
    [$course, $author, $levels] = ownedCourse();

    foreach (['Unit 1', 'Unit 2', 'Unit 3'] as $title) {
        $this->actingAs($author)
            ->post("/studio/courses/{$course->id}/nodes", [
                'parent_id' => null,
                'schema_level_id' => $levels['Part']->id,
                'title' => $title,
                'after_node_id' => null,
            ])
            ->assertSessionHas('success');
    }

    $order = CourseNode::whereNull('parent_id')->orderBy('sort_key')->pluck('title')->all();
    expect($order)->toBe(['Unit 1', 'Unit 2', 'Unit 3']);
});

it('adds a child under a node', function () {
    [$course, $author, $levels] = ownedCourse();
    $part = app(CourseTree::class)->createNode($course, $levels['Part'], 'Part One');

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/courses/{$course->id}/nodes", [
            'parent_id' => $part->id,
            'schema_level_id' => $levels['Chapter']->id,
            'title' => 'Chapter One',
            'after_node_id' => null,
        ])
        ->assertSessionHas('success');

    $child = CourseNode::where('parent_id', $part->id)->sole();
    expect($child->title)->toBe('Chapter One')
        ->and($child->depth)->toBe(1);
});

/** The structure trigger is the authority; the controller only translates it. */
it('refuses a level nested under the wrong parent', function () {
    [$course, $author, $levels] = ownedCourse();
    $part = app(CourseTree::class)->createNode($course, $levels['Part'], 'Part One');

    // Topic belongs under Chapter, not directly under Part.
    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/courses/{$course->id}/nodes", [
            'parent_id' => $part->id,
            'schema_level_id' => $levels['Topic']->id,
            'title' => 'Stray Topic',
            'after_node_id' => null,
        ])
        ->assertSessionHas('error', 'That level is not allowed directly inside this node.');

    expect(CourseNode::where('title', 'Stray Topic')->exists())->toBeFalse();
});

it('refuses a non-root level at the top of the course', function () {
    [$course, $author, $levels] = ownedCourse();

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/courses/{$course->id}/nodes", [
            'parent_id' => null,
            'schema_level_id' => $levels['Chapter']->id,
            'title' => 'Orphan Chapter',
            'after_node_id' => null,
        ])
        ->assertSessionHas('error', 'That level cannot sit at the top of the course.');
});

it('refuses a schema level from another schema version', function () {
    [$course, $author] = ownedCourse();
    $foreign = publish(textbookSchema())->levels()->where('name', 'Part')->sole();

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/courses/{$course->id}/nodes", [
            'parent_id' => null,
            'schema_level_id' => $foreign->id,
            'title' => 'Wrong Schema',
            'after_node_id' => null,
        ])
        ->assertSessionHasErrors('schema_level_id');
});

it('caps a level at its max occurrences', function () {
    [$course, $author, $levels] = ownedCourse();
    $tree = app(CourseTree::class);
    $part = $tree->createNode($course, $levels['Part'], 'Part One');
    $chapter = $tree->createNode($course, $levels['Chapter'], 'Chapter One', $part);

    // Topic has max_occurrences = 40. The add menu reports remaining, and the
    // create is refused past the cap. Prove the reporting, not all 40 inserts.
    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(function (AssertableInertia $page) use ($chapter) {
            $chapterNode = collect($page->toArray()['props']['tree'][0]['children'])
                ->firstWhere('id', $chapter->id);

            expect($chapterNode['add_levels'][0]['name'])->toBe('Topic')
                ->and($chapterNode['add_levels'][0]['remaining'])->toBe(40);
        });
});

/*
|--------------------------------------------------------------------------
| Renaming, reordering, deleting
|--------------------------------------------------------------------------
*/

it('renames a node without changing its slug', function () {
    [$course, $author, $levels] = ownedCourse();
    $part = app(CourseTree::class)->createNode($course, $levels['Part'], 'Part One');
    $originalSlug = $part->slug;

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->patch("/studio/course-nodes/{$part->id}", ['title' => 'Part The First'])
        ->assertSessionHas('success');

    $part->refresh();
    expect($part->title)->toBe('Part The First')
        ->and($part->slug)->toBe($originalSlug);
});

it('reorders a node among its siblings', function () {
    [$course, $author, $levels] = ownedCourse();
    $tree = app(CourseTree::class);
    $a = $tree->createNode($course, $levels['Part'], 'A');
    $b = $tree->createNode($course, $levels['Part'], 'B', null, $a->id);

    // Move A to follow B.
    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->post("/studio/course-nodes/{$a->id}/move", ['after_node_id' => $b->id])
        ->assertSessionHas('success');

    $order = CourseNode::orderBy('sort_key')->pluck('title')->all();
    expect($order)->toBe(['B', 'A']);
});

it('deletes a node and its whole subtree', function () {
    [$course, $author, $levels] = ownedCourse();
    $tree = app(CourseTree::class);
    $part = $tree->createNode($course, $levels['Part'], 'Part One');
    $chapter = $tree->createNode($course, $levels['Chapter'], 'Chapter One', $part);
    $tree->createNode($course, $levels['Topic'], 'Topic One', $chapter);

    $this->actingAs($author)
        ->from("/studio/courses/{$course->id}")
        ->delete("/studio/course-nodes/{$part->id}")
        ->assertSessionHas('success');

    // The whole branch is gone, not just the top node.
    expect(CourseNode::count())->toBe(0)
        ->and(CourseNode::withTrashed()->count())->toBe(3);
});

/** A freed slug can be reused: the soft-deleted subtree no longer occupies it. */
it('frees a deleted node s slug for reuse', function () {
    [$course, $author, $levels] = ownedCourse();
    $tree = app(CourseTree::class);
    $part = $tree->createNode($course, $levels['Part'], 'Part One');

    $tree->deleteNode($part);

    $replacement = $tree->createNode($course, $levels['Part'], 'Part One');
    expect($replacement->slug)->toBe('part-one');
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('grants an admin edit through the Gate bypass', function () {
    [$course] = ownedCourse();

    // Admin holds no editing grant on this course, but the Gate::before bypass
    // (AppServiceProvider) admits them to every ability except `review`.
    $this->actingAs(staff(Roles::ADMIN))
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit', true));
});

it('refuses a course to an author with no grant on it', function () {
    [$course] = ownedCourse();

    // CoursePolicy::view needs a grant; an author holds course.view.granted but
    // none on this course, so they cannot even open it.
    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get("/studio/courses/{$course->id}")
        ->assertForbidden();
});

it('shows a granted reviewer a read-only tree', function () {
    [$course, $author, $levels] = ownedCourse();
    app(CourseTree::class)->createNode($course, $levels['Part'], 'Part One');

    $reviewer = staff(Roles::CONTENT_REVIEWER);
    grant($reviewer, $course, CourseGrant::REVIEWER);

    // A reviewer may read the course but holds no editing grant: view yes,
    // edit no.
    $this->actingAs($reviewer)
        ->get("/studio/courses/{$course->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tree', 1)
            ->where('can.edit', false)
        );
});

it('refuses structural edits without an editing grant', function () {
    [$course, , $levels] = ownedCourse();
    $stranger = staff(Roles::CONTENT_AUTHOR); // holds NODE_CREATE, but no grant here

    $this->actingAs($stranger)
        ->post("/studio/courses/{$course->id}/nodes", [
            'parent_id' => null,
            'schema_level_id' => $levels['Part']->id,
            'title' => 'Sneaky',
            'after_node_id' => null,
        ])
        ->assertForbidden();

    expect(CourseNode::count())->toBe(0);
});

it('refuses a reviewer any structural edit', function () {
    [$course, , $levels] = ownedCourse();
    $reviewer = staff(Roles::CONTENT_REVIEWER);
    grant($reviewer, $course, CourseGrant::REVIEWER);

    // A reviewer's grant is not an editing grant, and they lack NODE_CREATE.
    $this->actingAs($reviewer)
        ->post("/studio/courses/{$course->id}/nodes", [
            'parent_id' => null,
            'schema_level_id' => $levels['Part']->id,
            'title' => 'Nope',
            'after_node_id' => null,
        ])
        ->assertForbidden();
});

it('sends a guest away from a course', function () {
    [$course] = ownedCourse();

    $this->get("/studio/courses/{$course->id}")->assertRedirect('/studio/login');
});
