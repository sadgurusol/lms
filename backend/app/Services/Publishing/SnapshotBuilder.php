<?php

namespace App\Services\Publishing;

use App\ContentBlocks\BlockType;
use App\Models\ContentBlock;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\Media;
use App\Models\SchemaLevel;
use App\Services\Media\MediaPlayback;
use Illuminate\Support\Collection;

/**
 * Builds the self-contained snapshot a client can render offline with no
 * further API calls: the tree, the baked-in numbering, and a media manifest to
 * pre-download.
 */
final class SnapshotBuilder
{
    public function __construct(private readonly MediaPlayback $playback) {}

    /** @return array<string, mixed> */
    public function build(Course $course): array
    {
        $version = $course->schemaVersion;
        $levels = $version->levels()->get()->keyBy('id');

        // blocks.media is eager-loaded: block() denormalises playback data, and
        // lazy-loading it would be one query per video in the course.
        $nodes = $course->nodes()->with('blocks.media')->orderBy('path')->get();

        /** @var array<string, list<CourseNode>> $byParent */
        $byParent = [];
        foreach ($nodes as $node) {
            $byParent[$node->parent_id ?? ''][] = $node;
        }

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'code' => $course->code,
                'subject' => $course->subject,
                'grade_band' => $course->grade_band,
                'language' => $course->language,
            ],
            'schema' => [
                'id' => $version->id,
                'version' => $version->version,
                'levels' => $levels->values()->map(fn (SchemaLevel $l) => [
                    'id' => $l->id,
                    'parent_level_id' => $l->parent_level_id,
                    'name' => $l->name,
                    'plural_name' => $l->plural_name,
                    'depth' => $l->depth,
                ])->all(),
            ],
            'tree' => $this->buildBranch($byParent, $levels, ''),
        ];
    }

    /**
     * Everything the offline pack must download before the course works on a
     * plane. Deduplicated: the same diagram used in six topics is one entry.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public function mediaManifest(array $snapshot): array
    {
        $ids = [];
        $this->collectMediaIds($snapshot['tree'], $ids);

        return Media::query()
            ->whereIn('id', array_unique($ids))
            ->get()
            ->map(fn (Media $m) => [
                'media_id' => $m->id,
                'kind' => $m->kind,
                'mime' => $m->mime,
                'bytes' => $m->size_bytes,
                'checksum_sha256' => $m->checksum_sha256,
                'playback_id' => $m->playback_id,
                'duration_s' => $m->duration_s,
            ])
            ->values()
            ->all();
    }

    public function etag(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, list<CourseNode>>  $byParent
     * @param  Collection<array-key, SchemaLevel>  $levels
     * @return list<array<string, mixed>>
     */
    private function buildBranch(array $byParent, Collection $levels, string $parentKey): array
    {
        $siblings = $byParent[$parentKey] ?? [];
        usort($siblings, fn (CourseNode $a, CourseNode $b) => strcmp($a->sort_key, $b->sort_key));

        // Numbering restarts per level within a parent: two Chapters and three
        // Topics under one Part are "Chapter 1..2" and "1..3", not "1..5".
        $positions = [];
        $branch = [];

        foreach ($siblings as $node) {
            $level = $levels[$node->schema_level_id];
            $position = $positions[$level->id] = ($positions[$level->id] ?? 0) + 1;

            $number = Numbering::format($level->numbering_style, $position);

            $branch[] = [
                'id' => $node->id,
                'level_id' => $level->id,
                'title' => $node->title,
                'slug' => $node->slug,
                'summary' => $node->summary,
                'number' => $number,
                'label' => Numbering::label($level->label_template, $number, $node->title),
                'path' => $node->path,
                'blocks' => $node->blocks
                    ->sortBy('sort_key', SORT_STRING)
                    ->map(fn (ContentBlock $b) => $this->block($b))
                    ->values()->all(),
                'children' => $this->buildBranch($byParent, $levels, $node->id),
            ];
        }

        return $branch;
    }

    /** @return array<string, mixed> */
    private function block(ContentBlock $block): array
    {
        $payload = $block->payload ?? [];
        $type = $block->blockType();

        // Denormalise playback data into the snapshot so the player needs no
        // second round trip — and so an offline pack is genuinely self-contained.
        if ($type->requiresReadyMedia() && $media = $block->media) {
            $payload['playback_id'] ??= $media->playback_id;
            $payload['duration_s'] ??= $media->duration_s;

            if ($type === BlockType::Video) {
                // The streaming source (HLS from a provider, or a direct mp4 from
                // our own endpoint in dev), plus a poster — everything the player
                // needs to start without another request.
                if ($resolved = $this->playback->video($media)) {
                    $payload = [...$payload, ...$resolved];
                }
            } elseif (($url = $media->url()) !== null) {
                // A servable URL for image/attachment.
                $payload['url'] ??= $url;
            }
        }

        return [
            'id' => $block->id,
            'type' => $block->type,
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $branch
     * @param  array<int, string>  $ids
     */
    private function collectMediaIds(array $branch, array &$ids): void
    {
        foreach ($branch as $node) {
            foreach ($node['blocks'] as $block) {
                if (BlockType::from($block['type'])->requiresReadyMedia()) {
                    $ids[] = (string) $block['payload']['media_id'];
                }
            }

            $this->collectMediaIds($node['children'], $ids);
        }
    }
}
