<?php

namespace App\Http\Controllers\Studio;

use App\Ai\AiPlatformClient;
use App\ContentBlocks\BlockType;
use App\Http\Controllers\Controller;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Services\Generation\LessonExpander;
use App\Services\Tree\CourseTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The interactive ("build one step at a time") lesson authoring flow for the
 * studio — the LMS counterpart of ischool's InteractiveLessonController (docs/14
 * WS3). Generates steps for a Lesson node via the ai-platform; the author reviews
 * / revises / accepts each, then commits them as child Step nodes with blocks
 * (reusing WS2's LessonExpander). JSON endpoints (session-cookie auth), so the
 * React builder can poll job status.
 */
class LessonBuilderController extends Controller
{
    /** The lesson's steps (child nodes + blocks) for the step player / preview. */
    public function preview(CourseNode $lesson): JsonResponse
    {
        Gate::authorize('view', $lesson);

        $steps = $lesson->children()->with('blocks')->orderBy('sort_key')->get()->map(fn (CourseNode $n) => [
            'id' => $n->id,
            'title' => $n->title,
            'blocks' => $n->blocks->map(fn ($b) => ['type' => $b->type, 'payload' => $b->payload])->values(),
        ]);

        return response()->json(['title' => $lesson->title, 'steps' => $steps]);
    }

    /** Draft the next step. Returns { job_id }. */
    public function nextStep(Request $request, CourseNode $lesson, AiPlatformClient $platform): JsonResponse
    {
        $this->authorizeBuild($lesson, $platform);

        $data = $request->validate([
            'steps' => ['array'],
            'steps.*' => ['array'],
            'step_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'target_steps' => ['nullable', 'integer', 'min:1', 'max:30'],
            'animated' => ['boolean'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $jobId = $platform->startStep(
            $this->context($lesson),
            $data['steps'] ?? [],
            $data['step_number'] ?? null,
            $data['target_steps'] ?? null,
            $data['animated'] ?? true,
            $data['feedback'] ?? null,
        );

        return response()->json(['job_id' => $jobId, 'status' => 'queued']);
    }

    /** Revise one step per feedback. Returns { job_id }. */
    public function reviseStep(Request $request, CourseNode $lesson, AiPlatformClient $platform): JsonResponse
    {
        $this->authorizeBuild($lesson, $platform);

        $data = $request->validate([
            'step' => ['required', 'array'],
            'feedback' => ['required', 'string', 'max:2000'],
            'steps' => ['array'],
            'steps.*' => ['array'],
            'animated' => ['boolean'],
        ]);

        $jobId = $platform->startReviseStep(
            $this->context($lesson),
            $data['step'],
            $data['feedback'],
            $data['steps'] ?? [],
            $data['animated'] ?? true,
        );

        return response()->json(['job_id' => $jobId, 'status' => 'queued']);
    }

    /**
     * Synthesize narration audio for a step's fragment voice lines, without
     * regenerating the step. Returns { audio_urls: [...] } parallel to the input,
     * which the builder maps back onto its draft fragments. Synchronous.
     */
    public function voice(Request $request, CourseNode $lesson, AiPlatformClient $platform): JsonResponse
    {
        $this->authorizeBuild($lesson, $platform);

        $data = $request->validate([
            'voices' => ['present', 'array', 'max:20'],
            'voices.*' => ['nullable', 'string', 'max:2000'],
        ]);

        // Normalise to plain strings so nulls don't upset the platform contract.
        $texts = array_map(fn ($v) => (string) ($v ?? ''), $data['voices']);

        return response()->json(['audio_urls' => $platform->synthesizeSpeech($texts)]);
    }

    /** Poll a step job. Returns { status, step?, is_last?, error? }. */
    public function stepStatus(Request $request, CourseNode $lesson, AiPlatformClient $platform): JsonResponse
    {
        Gate::authorize('update', $lesson->course);

        $data = $request->validate(['job_id' => ['required', 'string', 'max:100']]);

        return response()->json($platform->stepResult($data['job_id']));
    }

    /** Replace the lesson's steps with the accepted list (child Step nodes + blocks). */
    public function commit(Request $request, CourseNode $lesson, LessonExpander $expander, CourseTree $tree): JsonResponse
    {
        Gate::authorize('update', $lesson->course);
        abort_unless($lesson->course->isEditable(), 422, 'This course is not editable.');

        $data = $request->validate([
            'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['array'],
        ]);

        $stepLevel = $this->resolveStepLevel($lesson);
        abort_if($stepLevel === null, 422, 'This lesson has no child level that accepts animated lessons.');

        // Replace: the builder opens with the existing steps preloaded, so a commit
        // is the full, current list. Remove the old Step nodes, then recreate.
        foreach ($lesson->children()->get() as $child) {
            $tree->deleteNode($child);
        }

        $count = $expander->expand($lesson, $stepLevel, $data['steps']);

        $lesson->refresh()->load('children');

        return response()->json([
            'count' => $count,
            'steps' => $lesson->children->map(fn (CourseNode $n) => ['id' => $n->id, 'title' => $n->title]),
        ]);
    }

    private function authorizeBuild(CourseNode $lesson, AiPlatformClient $platform): void
    {
        Gate::authorize('update', $lesson->course);
        abort_unless($lesson->course->isEditable(), 422, 'This course is not editable.');
        abort_unless($platform->isEnabled(), 503, 'The AI platform is not enabled.');
        abort_if($this->resolveStepLevel($lesson) === null, 422, 'This lesson has no child level that accepts animated lessons.');
    }

    /** The child level of the lesson that carries animated-reveal content. */
    private function resolveStepLevel(CourseNode $lesson): ?SchemaLevel
    {
        return SchemaLevel::query()
            ->where('parent_level_id', $lesson->schema_level_id)
            ->get()
            ->first(fn (SchemaLevel $l) => $l->allows_content
                && in_array(BlockType::AnimatedReveal->value, $l->allowed_block_types ?? [], true));
    }

    /** @return array<string, mixed> */
    private function context(CourseNode $lesson): array
    {
        return array_filter([
            'topic' => $lesson->title,
            'chapter' => $lesson->parent?->title,
            'subject' => $lesson->course?->subject,
            'content' => $lesson->summary,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
