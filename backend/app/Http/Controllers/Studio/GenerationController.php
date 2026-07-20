<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\ContentBlocks\BlockType;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateCourseJob;
use App\Models\ContentBlock;
use App\Models\CourseGeneration;
use App\Models\CourseNode;
use App\Models\SchemaVersion;
use App\Services\Generation\GenerationSettings;
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
                'can_retry' => $g->status === CourseGeneration::FAILED
                    && ($g->source_type === CourseGeneration::SOURCE_BRIEF || $g->pdf_path !== null),
                'progress' => $this->progressFor($g),
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

    public function settings(Request $request, GenerationSettings $settings): Response
    {
        abort_unless($request->user()->can(Permissions::COURSE_CREATE), 403);

        return Inertia::render('generate/Settings', [
            'outlineInstructions' => $settings->outlineInstructions(),
            'contentInstructions' => $settings->contentInstructions(),
            // The fixed base prompts, shown read-only for context (the guidance
            // fields are appended to these).
            'baseOutlinePrompt' => GenerationSettings::BASE_OUTLINE_PROMPT,
            'baseContentPrompt' => GenerationSettings::BASE_CONTENT_PROMPT,
        ]);
    }

    public function updateSettings(Request $request, GenerationSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::COURSE_CREATE), 403);

        $data = $request->validate([
            'outlineInstructions' => ['nullable', 'string', 'max:4000'],
            'contentInstructions' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings->save($data['outlineInstructions'] ?? '', $data['contentInstructions'] ?? '');

        return back()->with('success', 'Generation settings saved.');
    }

    /**
     * Re-run a failed generation. Brief runs always replay; a PDF run only
     * replays while its uploaded file is still around (kept on failure).
     */
    public function retry(Request $request, CourseGeneration $generation): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::COURSE_CREATE), 403);
        abort_unless($generation->requested_by === $request->user()->id, 403);
        abort_unless($generation->status === CourseGeneration::FAILED, 422);

        if ($generation->source_type === CourseGeneration::SOURCE_PDF && $generation->pdf_path === null) {
            return back()->with('error', 'The uploaded PDF is no longer available — please upload it again.');
        }

        $generation->update(['status' => CourseGeneration::PENDING, 'error' => null]);
        GenerateCourseJob::dispatch($generation->id);

        return back()->with('success', 'Retrying generation — it will update below when ready.');
    }

    /**
     * How many of a running generation's topics have their content yet — for a
     * live "18/40 topics" readout while the content chain works. Two queries, and
     * only for a processing run that already has its structure built.
     *
     * @return array{done: int, total: int}|null
     */
    private function progressFor(CourseGeneration $g): ?array
    {
        if ($g->status !== CourseGeneration::PROCESSING || $g->course_id === null) {
            return null;
        }

        $contentNodeIds = CourseNode::query()
            ->where('course_id', $g->course_id)
            ->with('schemaLevel')
            ->get()
            ->filter(fn (CourseNode $n) => $n->permitsBlockType(BlockType::RichText->value))
            ->pluck('id');

        $total = $contentNodeIds->count();
        if ($total === 0) {
            return null;
        }

        $done = ContentBlock::query()
            ->whereIn('course_node_id', $contentNodeIds)
            ->where('type', BlockType::RichText->value)
            ->distinct()
            ->count('course_node_id');

        return ['done' => $done, 'total' => $total];
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
