<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateCourseJob;
use App\Models\CourseGeneration;
use App\Models\SchemaVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generate a draft course with AI, from a PDF textbook or a topic brief,
 * structured against a chosen schema. The heavy work runs in a queued job
 * ({@see GenerateCourseJob}); the result is always a draft for the author to
 * review before publishing. See docs/14-course-generation.md.
 */
class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::COURSE_CREATE), 403);

        $generations = CourseGeneration::query()
            ->where('requested_by', $request->user()->id)
            ->with(['schemaVersion.courseSchema:id,name', 'course:id,title'])
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (CourseGeneration $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'source_type' => $g->source_type,
                'status' => $g->status,
                'error' => $g->error,
                'course_id' => $g->course_id,
                'schema' => $g->schemaVersion->courseSchema->name.' v'.$g->schemaVersion->version,
                'created_at' => $g->created_at?->toIso8601String(),
            ]);

        return Inertia::render('generate/Index', [
            'generations' => $generations,
            'schemas' => $this->publishedSchemas(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::COURSE_CREATE), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'schema_version_id' => [
                'required', 'uuid',
                Rule::exists('schema_versions', 'id')->where('status', SchemaVersion::STATUS_PUBLISHED),
            ],
            'source_type' => ['required', Rule::in([CourseGeneration::SOURCE_PDF, CourseGeneration::SOURCE_BRIEF])],
            'brief' => ['required_if:source_type,brief', 'nullable', 'string', 'max:8000'],
            'pdf' => ['required_if:source_type,pdf', 'nullable', 'file', 'mimetypes:application/pdf', 'max:30720'],
        ]);

        $pdfPath = $data['source_type'] === CourseGeneration::SOURCE_PDF
            ? $request->file('pdf')->store('generations')
            : null;

        $generation = CourseGeneration::create([
            'requested_by' => $request->user()->id,
            'schema_version_id' => $data['schema_version_id'],
            'name' => $data['name'],
            'source_type' => $data['source_type'],
            'brief' => $data['source_type'] === CourseGeneration::SOURCE_BRIEF ? $data['brief'] : null,
            'pdf_path' => $pdfPath,
            'status' => CourseGeneration::PENDING,
        ]);

        GenerateCourseJob::dispatch($generation->id);

        return back()->with('success', 'Generation started — it will appear below when ready.');
    }

    /** @return Collection<int, array{id: string, name: non-falsy-string}> */
    private function publishedSchemas()
    {
        return SchemaVersion::query()
            ->where('status', SchemaVersion::STATUS_PUBLISHED)
            ->with('courseSchema:id,name')
            ->get()
            ->map(fn (SchemaVersion $v) => ['id' => $v->id, 'name' => $v->courseSchema->name.' v'.$v->version])
            ->sortBy('name')
            ->values();
    }
}
