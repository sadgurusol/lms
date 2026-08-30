<?php

namespace App\Http\Controllers\Studio;

use App\Ai\AiPlatformClient;
use App\ContentBlocks\BlockType;
use App\Http\Controllers\Controller;
use App\Models\CourseNode;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
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
            // A confirmed title drives the content and is kept unchanged.
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $jobId = $platform->startStep(
            $this->context($lesson),
            $data['steps'] ?? [],
            $data['step_number'] ?? null,
            $data['target_steps'] ?? null,
            $data['animated'] ?? true,
            $data['feedback'] ?? null,
            $data['title'] ?? null,
        );

        return response()->json(['job_id' => $jobId, 'status' => 'queued']);
    }

    /** Suggest a title for the next step (synchronous). Returns { title }. */
    public function suggestTitle(Request $request, CourseNode $lesson, AiPlatformClient $platform): JsonResponse
    {
        $this->authorizeBuild($lesson, $platform);

        $data = $request->validate([
            'steps' => ['array'],
            'steps.*' => ['array'],
            'step_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'target_steps' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $title = $platform->suggestTitle(
            $this->context($lesson),
            $data['steps'] ?? [],
            $data['step_number'] ?? null,
            $data['target_steps'] ?? null,
        );

        return response()->json(['title' => $title]);
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

        // The builder emits diagram/image (and reveal/audio/sim/animation) blocks.
        // A DB trigger drops any block type the Step level doesn't permit, and the
        // expander swallows that silently — so ensure the level permits them first.
        $this->ensureLevelPermitsBuilderBlocks($stepLevel);

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
            // Tell the author if a visual block couldn't be saved because the Step
            // level (a published, immutable schema) doesn't permit that type — so
            // a missing diagram/image is never a silent mystery.
            'warnings' => $this->droppedBlockWarnings($stepLevel, $data['steps']),
        ]);
    }

    /**
     * Block types present in the incoming steps that the Step level does not
     * permit (so the trigger dropped them). Returns a human message per type.
     *
     * @param  array<int, mixed>  $steps
     * @return list<string>
     */
    private function droppedBlockWarnings(SchemaLevel $level, array $steps): array
    {
        $allowed = $level->allowed_block_types ?? [];
        $gated = ['diagram', 'image', 'simulation', 'animation'];

        $present = [];
        foreach ($steps as $s) {
            $blocks = is_array($s) && is_array($s['blocks'] ?? null) ? $s['blocks'] : [];
            foreach ($blocks as $b) {
                $t = is_array($b) ? ($b['type'] ?? null) : null;
                if (in_array($t, $gated, true) && ! in_array($t, $allowed, true)) {
                    $present[$t] = true;
                }
            }
        }

        if ($present === []) {
            return [];
        }

        $types = implode(', ', array_keys($present));

        return ["Some {$types} block(s) were not saved: this lesson's Step level doesn't allow them. "
            .'Add those block types to the Step level in the schema editor to keep them '
            .'(a published schema must be cloned to a draft first).'];
    }

    private function authorizeBuild(CourseNode $lesson, AiPlatformClient $platform): void
    {
        Gate::authorize('update', $lesson->course);
        abort_unless($lesson->course->isEditable(), 422, 'This course is not editable.');
        abort_unless($platform->isEnabled(), 503, 'The AI platform is not enabled.');
        abort_if($this->resolveStepLevel($lesson) === null, 422, 'This lesson has no child level that accepts animated lessons.');
    }

    /** Block types the interactive builder can produce for a Step node. */
    private const BUILDER_BLOCK_TYPES = [
        BlockType::RichText,
        BlockType::AnimatedReveal,
        BlockType::Audio,
        BlockType::Image,
        BlockType::Diagram,
        BlockType::Simulation,
        BlockType::Animation,
    ];

    /**
     * Make sure the Step level permits every block type the builder emits, so the
     * content_blocks level trigger doesn't silently drop diagrams/images on commit.
     * Additive only, and only on a draft schema version — a published one is
     * immutable (enforced by its own trigger), so we leave it untouched; a block
     * type it lacks will simply not be attached, exactly as before.
     */
    private function ensureLevelPermitsBuilderBlocks(SchemaLevel $level): void
    {
        // Published schema versions are immutable — never modify one. Query the
        // status by id rather than via the relation (lazy loading is disabled).
        $isPublished = SchemaVersion::query()
            ->whereKey($level->schema_version_id)
            ->where('status', SchemaVersion::STATUS_PUBLISHED)
            ->exists();
        if ($isPublished) {
            return;
        }

        $current = $level->allowed_block_types ?? [];
        $needed = array_map(fn (BlockType $t) => $t->value, self::BUILDER_BLOCK_TYPES);
        $merged = array_values(array_unique([...$current, ...$needed]));

        if (count($merged) !== count($current)) {
            $level->allowed_block_types = $merged;
            $level->save();
        }
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
