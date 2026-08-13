<?php

namespace App\Http\Controllers\Studio;

use App\ContentBlocks\BlockType;
use App\ContentBlocks\InvalidBlockPayload;
use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\Media;
use App\Services\Content\BlockEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The content blocks on a single node. Every action borrows the course's
 * authority through CourseNodePolicy; payload *shape* is validated by the model's
 * saving hook, which this controller only translates into a field error.
 */
class ContentBlockController extends Controller
{
    public function index(Request $request, CourseNode $node): Response
    {
        Gate::authorize('view', $node);

        $node->load('course.schemaVersion.courseSchema', 'schemaLevel');
        // A published course is a frozen snapshot: viewable, not editable. The
        // mutation endpoints already refuse; this keeps the UI honest too.
        $editable = Gate::allows('update', $node->course) && $node->course->isEditable();

        return Inertia::render('nodes/Content', [
            'node' => [
                'id' => $node->id,
                'title' => $node->title,
                'level_name' => $node->schemaLevel->name,
                'allows_content' => $node->schemaLevel->allows_content,
            ],
            'course' => [
                'id' => $node->course->id,
                'title' => $node->course->title,
            ],
            'blocks' => $node->blocks()->with('media')->get()->map(fn (ContentBlock $block) => [
                'id' => $block->id,
                'type' => $block->type,
                'payload' => $this->withMediaUrl($block),
            ])->values(),
            'addable_types' => $this->addableTypes($node),
            'can' => ['edit' => $editable, 'build_lesson' => $editable && $this->hasAnimatedStepChild($node)],
        ]);
    }

    /** Does this node have a child level that accepts animated-reveal lessons? */
    private function hasAnimatedStepChild(CourseNode $node): bool
    {
        return \App\Models\SchemaLevel::query()
            ->where('parent_level_id', $node->schema_level_id)
            ->get()
            ->contains(fn ($l) => $l->allows_content
                && in_array(\App\ContentBlocks\BlockType::AnimatedReveal->value, $l->allowed_block_types ?? [], true));
    }

    public function store(Request $request, CourseNode $node, BlockEditor $editor): RedirectResponse
    {
        Gate::authorize('attachBlock', $node);
        $this->assertEditable($node->course);

        $data = $request->validate([
            'type' => ['required', Rule::in(BlockEditor::AUTHORABLE)],
            'after_block_id' => ['nullable', 'uuid'],
        ]);

        try {
            // A position places the block after it; none means append to the end.
            $data['after_block_id'] ?? null
                ? $editor->create($node, $data['type'], $data['after_block_id'])
                : $editor->append($node, $data['type']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Block added.');
    }

    /** Attach an image or attachment block to an already-uploaded media asset. */
    public function storeMedia(Request $request, CourseNode $node, BlockEditor $editor): RedirectResponse
    {
        Gate::authorize('attachBlock', $node);
        $this->assertEditable($node->course);

        $data = $request->validate([
            'type' => ['required', Rule::in(BlockEditor::MEDIA_TYPES)],
            'media_id' => ['required', 'uuid'],
            'alt' => ['nullable', 'string', 'max:500'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);

        $media = Media::query()->where('status', Media::STATUS_READY)->find($data['media_id']);
        abort_if($media === null, 422, 'That upload is not ready.');

        // Shape the payload the way the type's JSON Schema expects.
        $payload = match ($data['type']) {
            BlockType::Image->value => array_filter([
                'alt' => $data['alt'] ?? $media->original_filename ?? 'image',
                'caption' => $data['caption'] ?? null,
            ], fn ($v) => $v !== null),
            // Video carries only media_id here; playback data is baked at publish.
            BlockType::Video->value => [],
            default => array_filter([
                'filename' => $media->original_filename ?? 'file',
                'size_bytes' => $media->size_bytes,
                'description' => $data['caption'] ?? null,
            ], fn ($v) => $v !== null),
        };

        try {
            $editor->appendMedia($node, $data['type'], $media->id, $payload);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Block added.');
    }

    public function update(Request $request, ContentBlock $block, BlockEditor $editor): RedirectResponse
    {
        Gate::authorize('update', $block);
        $this->assertEditable($block->courseNode->course);

        $data = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        try {
            $editor->updatePayload($block, $data['payload']);
        } catch (InvalidBlockPayload $e) {
            // The JSON-Schema failure is precise but not for an author's eyes.
            // Attach it to the field so the form shows it inline.
            throw ValidationException::withMessages([
                'payload' => $this->humanise($block->blockType(), $e),
            ]);
        }

        return back()->with('success', 'Saved.');
    }

    public function move(Request $request, ContentBlock $block, BlockEditor $editor): RedirectResponse
    {
        Gate::authorize('update', $block);
        $this->assertEditable($block->courseNode->course);

        $data = $request->validate(['after_block_id' => ['nullable', 'uuid']]);

        try {
            $editor->reorder($block, $data['after_block_id'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reordered.');
    }

    public function destroy(Request $request, ContentBlock $block, BlockEditor $editor): RedirectResponse
    {
        Gate::authorize('delete', $block);
        $this->assertEditable($block->courseNode->course);

        $editor->delete($block);

        return back()->with('success', 'Block removed.');
    }

    /**
     * A published course is a frozen snapshot; its content cannot change until it
     * is revised into a new draft. Enforced beside the permission check so it
     * holds for admins too, whom Gate::before would otherwise wave through.
     */
    private function assertEditable(Course $course): void
    {
        abort_unless(
            $course->isEditable(),
            403,
            'This course is published. Start a new version to edit it.',
        );
    }

    /** @return list<string> */
    private function addableTypes(CourseNode $node): array
    {
        if (! $node->schemaLevel->allows_content) {
            return [];
        }

        // Text/callout/embed are authored inline; image/attachment need an upload
        // first. The editor tells them apart and offers the right flow.
        $supported = [...BlockEditor::AUTHORABLE, ...BlockEditor::MEDIA_TYPES];

        return array_values(array_filter(
            $supported,
            fn (string $type) => $node->permitsBlockType($type),
        ));
    }

    /**
     * The block's payload with a servable media URL folded in, so the editor can
     * render an image without a second request. Non-media blocks are unchanged.
     *
     * @return array<string, mixed>
     */
    private function withMediaUrl(ContentBlock $block): array
    {
        $payload = $block->payload ?? [];

        if ($block->media === null) {
            return $payload;
        }

        if (($url = $block->media->url()) !== null) {
            $payload['url'] = $url;
        }

        // Video is not playable inline in the editor (the learner stream needs a
        // bearer token); surface enough to render an informative placeholder.
        if ($block->media->kind === Media::KIND_VIDEO) {
            $payload['media_status'] = $block->media->status;
            $payload['duration_s'] = $block->media->duration_s;
            $payload['filename'] = $block->media->original_filename;
        }

        return $payload;
    }

    private function humanise(BlockType $type, InvalidBlockPayload $e): string
    {
        return match ($type) {
            BlockType::Embed => 'Enter a valid https:// URL and a supported provider.',
            BlockType::Callout => 'A callout needs a variant and some body content.',
            default => 'That content could not be saved. Check the required fields.',
        };
    }
}
