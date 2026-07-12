<?php

use App\Authorization\Roles;
use App\ContentBlocks\BlockType;
use App\Models\CourseGrant;
use App\Services\Content\BlockEditor;
use App\Services\Publishing\PublishCourse;
use App\Services\Tree\CourseTree;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('previews the draft course as a learner-facing snapshot', function () {
    [$course, $part, $chapter] = textbookCourse();
    $author = staff(Roles::CONTENT_AUTHOR);
    grant($author, $course, CourseGrant::OWNER);

    $tree = app(CourseTree::class);
    $partNode = $tree->appendNode($course, $part, 'Foundations');
    $chapterNode = $tree->appendNode($course, $chapter, 'First Steps', $partNode);
    app(BlockEditor::class)->append($chapterNode, BlockType::RichText->value);

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/preview")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Preview')
            ->where('snapshot.course.title', $course->title)
            ->where('context.kind', 'draft')
            ->where('context.workflow_state', 'draft')
            ->has('snapshot.tree', 1)
            ->where('snapshot.tree.0.title', 'Foundations')
            // Numbering is baked in, exactly as it will be for learners.
            ->where('snapshot.tree.0.label', 'Part I')
            ->has('snapshot.tree.0.children', 1)
            ->where('snapshot.tree.0.children.0.title', 'First Steps')
            ->has('snapshot.tree.0.children.0.blocks', 1)
        );
});

it('lets a granted reviewer preview', function () {
    [$course] = textbookCourse();
    $reviewer = staff(Roles::CONTENT_REVIEWER);
    grant($reviewer, $course, CourseGrant::REVIEWER);

    $this->actingAs($reviewer)
        ->get("/studio/courses/{$course->id}/preview")
        ->assertInertia(fn (AssertableInertia $page) => $page->component('courses/Preview'));
});

it('refuses a preview to someone with no grant', function () {
    [$course] = textbookCourse();

    $this->actingAs(staff(Roles::CONTENT_AUTHOR))
        ->get("/studio/courses/{$course->id}/preview")
        ->assertForbidden();
});

it('sends a guest away from the preview', function () {
    [$course] = textbookCourse();

    $this->get("/studio/courses/{$course->id}/preview")->assertRedirect('/studio/login');
});

/*
|--------------------------------------------------------------------------
| The published (read-only, live) view
|--------------------------------------------------------------------------
*/

it('renders the published version from its frozen snapshot', function () {
    [$course, $author] = publishableCourse();
    app(PublishCourse::class)->handle($course, staff(Roles::ADMIN));

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/published")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses/Preview')
            ->where('context.kind', 'published')
            ->where('context.version', 1)
            ->has('snapshot.tree', 1)
        );
});

/**
 * The published view is served from the publication snapshot, so it does not
 * change when the draft is revised and edited — that is the whole point.
 */
it('keeps the published view stable while a new draft is edited', function () {
    [$course, $author] = publishableCourse();
    $admin = staff(Roles::ADMIN);
    app(PublishCourse::class)->handle($course, $admin);

    // Start a new version and rename a node in the draft.
    app(PublishCourse::class)->revise($course->fresh(), $admin);
    $topic = $course->nodes()->whereHas('schemaLevel', fn ($q) => $q->where('name', 'Topic'))->firstOrFail();
    app(CourseTree::class)->renameNode($topic, 'Topic One CHANGED');

    // The published view still shows the original title; the draft preview shows the change.
    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/published")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('context.kind', 'published')
            ->where('snapshot.tree.0.children.0.children.0.title', 'Topic One')
        );

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/preview")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('context.kind', 'draft')
            ->where('snapshot.tree.0.children.0.children.0.title', 'Topic One CHANGED')
        );
});

it('404s the published view for a course never published', function () {
    [$course, $author] = publishableCourse();

    $this->actingAs($author)
        ->get("/studio/courses/{$course->id}/published")
        ->assertNotFound();
});
