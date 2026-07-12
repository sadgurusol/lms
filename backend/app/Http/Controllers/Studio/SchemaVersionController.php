<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\ContentBlocks\BlockType;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Services\Schemas\CloneSchemaVersion;
use App\Services\Schemas\PublishSchemaVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SchemaVersionController extends Controller
{
    public function show(Request $request, SchemaVersion $version): Response
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_VIEW), 403);

        $version->load('courseSchema');

        return Inertia::render('schemas/Show', [
            'schema' => [
                'id' => $version->courseSchema->id,
                'name' => $version->courseSchema->name,
                'slug' => $version->courseSchema->slug,
            ],
            'version' => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'published_at' => $version->published_at?->toIso8601String(),
                // A published version is immutable, enforced by a trigger. The
                // UI must not offer edits it knows the database will refuse.
                'editable' => $version->isDraft(),
            ],
            'levels' => $version->levels()->get()->map(fn (SchemaLevel $level) => [
                'id' => $level->id,
                'parent_level_id' => $level->parent_level_id,
                'name' => $level->name,
                'plural_name' => $level->plural_name,
                'depth' => $level->depth,
                'sort_key' => $level->sort_key,
                'min_occurrences' => $level->min_occurrences,
                'max_occurrences' => $level->max_occurrences,
                'allows_content' => $level->allows_content,
                'allowed_block_types' => $level->allowed_block_types,
                'allows_assessment' => $level->allows_assessment,
                'numbering_style' => $level->numbering_style,
                'label_template' => $level->label_template,
            ]),
            'options' => [
                'block_types' => BlockType::names(),
                'numbering_styles' => SchemaLevel::NUMBERING_STYLES,
            ],
            // Courses bind to a *version*. Editing a published one would rewrite
            // the meaning of every course already authored against it.
            'courses_bound' => Course::where('schema_version_id', $version->id)->count(),
            'can' => [
                'update' => $request->user()->can(Permissions::SCHEMA_UPDATE) && $version->isDraft(),
                'publish' => $request->user()->can(Permissions::SCHEMA_PUBLISH) && $version->isDraft(),
                'clone' => $request->user()->can(Permissions::SCHEMA_CREATE) && $version->isPublished(),
            ],
        ]);
    }

    public function publish(Request $request, SchemaVersion $version, PublishSchemaVersion $publisher): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_PUBLISH), 403);

        try {
            $publisher->handle($version, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Version {$version->version} published. It is now immutable.");
    }

    /** "Edit this published schema" means: clone it into a new draft. */
    public function clone(Request $request, SchemaVersion $version, CloneSchemaVersion $cloner): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_CREATE), 403);

        try {
            $draft = $cloner->handle($version);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('studio.schema-versions.show', $draft)
            ->with('success', "Cloned into draft version {$draft->version}.");
    }
}
