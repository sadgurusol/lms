<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSchema;
use App\Models\SchemaVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SchemaController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_VIEW), 403);

        $schemas = CourseSchema::query()
            ->with(['versions' => fn ($q) => $q->orderByDesc('version')])
            ->withCount('versions')
            ->orderBy('name')
            ->get();

        // How many courses bind to each version, so a schema in use cannot be
        // deleted from under the courses that depend on it. One grouped query.
        $courseCounts = Course::query()
            ->whereIn('schema_version_id', $schemas->pluck('versions')->flatten()->pluck('id'))
            ->selectRaw('schema_version_id, count(*) as total')
            ->groupBy('schema_version_id')
            ->pluck('total', 'schema_version_id');

        return Inertia::render('schemas/Index', [
            'schemas' => $schemas->map(fn (CourseSchema $schema) => [
                'id' => $schema->id,
                'name' => $schema->name,
                'slug' => $schema->slug,
                'description' => $schema->description,
                'version_count' => $schema->versions_count,
                'course_count' => (int) $schema->versions->sum(fn (SchemaVersion $v) => $courseCounts[$v->id] ?? 0),
                'draft' => $this->summarise($schema->versions->firstWhere('status', SchemaVersion::STATUS_DRAFT)),
                'published' => $this->summarise(
                    $schema->versions->firstWhere('status', SchemaVersion::STATUS_PUBLISHED)
                ),
            ]),
            'can' => [
                'create' => $request->user()->can(Permissions::SCHEMA_CREATE),
                'delete' => $request->user()->can(Permissions::SCHEMA_ARCHIVE),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_CREATE), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $version = DB::transaction(function () use ($data, $request) {
            $schema = CourseSchema::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // A schema without a version cannot be edited, so mint the draft now.
            return SchemaVersion::create([
                'course_schema_id' => $schema->id,
                'version' => 1,
                'status' => SchemaVersion::STATUS_DRAFT,
            ]);
        });

        return redirect()
            ->route('studio.schema-versions.show', $version)
            ->with('success', 'Schema created. Add its levels, then publish.');
    }

    public function destroy(Request $request, CourseSchema $schema): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_ARCHIVE), 403);

        // Refuse if any course binds to one of this schema's versions — deleting
        // it would strand those courses' structure and numbering.
        $courseCount = Course::whereIn('schema_version_id', $schema->versions()->pluck('id'))->count();

        if ($courseCount > 0) {
            return back()->with(
                'error',
                "This schema is used by {$courseCount} course(s). Retire those courses before deleting it.",
            );
        }

        // A soft delete: its published versions are immutable (a database trigger
        // forbids their deletion), so the schema is hidden, not hard-removed.
        $schema->delete();

        return redirect()
            ->route('studio.schemas.index')
            ->with('success', 'Schema deleted.');
    }

    /** @return array<string, mixed>|null */
    private function summarise(?SchemaVersion $version): ?array
    {
        return $version === null ? null : [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'schema';
        $slug = $base;
        $suffix = 2;

        while (CourseSchema::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
