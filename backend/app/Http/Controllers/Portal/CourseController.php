<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePublication;
use App\Portal\CourseGate;
use App\Portal\LessonCounter;
use App\Portal\PreviewGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, unauthenticated read access to a portal course — its landing (outline
 * + counts) and its full snapshot (for the player). Reads the baked
 * {@see CoursePublication} snapshot with no rebuild; {@see CourseGate} decides
 * accessibility and {@see PreviewGate} strips lessons past the free-preview limit.
 */
class CourseController extends Controller
{
    public function __construct(private readonly CourseGate $gate) {}

    /** Landing page data: course meta + the outline (tree without blocks) + counts. */
    public function show(Course $course): JsonResponse
    {
        $publication = $this->publication($course);
        $tree = $publication->snapshot['tree'] ?? [];
        $free = $this->gate->freePreview($course);
        $locked = $this->lockedIds($tree, $free);

        return response()->json([
            'course' => [
                'slug' => $course->slug ?: $course->id,
                'title' => $course->title,
                'subject' => $course->subject,
                'grade_band' => $course->grade_band,
                'language' => $course->language,
            ],
            'published_at' => $publication->published_at?->toIso8601String(),
            'outline' => $this->outline($tree, $locked),
            'counts' => ['lessons' => LessonCounter::count($tree)],
            'access' => ['free_preview' => $free],
        ]);
    }

    /**
     * The published snapshot for the player. Lessons past the free-preview limit
     * are stripped of content and flagged `locked`, so gated material never leaves
     * the server. ETag varies with the preview limit so caches stay correct.
     */
    public function content(Request $request, Course $course): JsonResponse
    {
        $publication = $this->publication($course);
        $free = $this->gate->freePreview($course);
        $etag = $publication->snapshot_etag.($free ? "-p{$free}" : '');

        $sent = trim(preg_replace('/^W\//', '', (string) $request->header('If-None-Match')) ?? '', '"');
        if ($sent !== '' && $sent === $etag) {
            return response()->json(null, 304)->setEtag($etag);
        }

        $snapshot = $publication->snapshot;
        $lockedCount = 0;
        if ($free !== null) {
            [$snapshot['tree'], $lockedCount] = PreviewGate::apply($snapshot['tree'] ?? [], $free);
        }

        // Point self-hosted (local) video at the public stream route; the baked
        // src is the bearer-gated one. Mux HLS is already a public CDN URL.
        $snapshot['tree'] = $this->publicizeVideos($snapshot['tree'] ?? []);

        return response()->json([
            'publication' => [
                'id' => $publication->id,
                'number' => $publication->number,
                'published_at' => $publication->published_at?->toIso8601String(),
            ],
            ...$snapshot,
            'media_manifest' => $publication->media_manifest,
            'access' => ['free_preview' => $free, 'locked_lessons' => $lockedCount],
        ])->setEtag($etag);
    }

    /**
     * Rewrite self-hosted video `src` to the public stream route (by media_id).
     * HLS (Mux) sources are already public CDN URLs and left untouched.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return array<int, array<string, mixed>>
     */
    private function publicizeVideos(array $tree): array
    {
        foreach ($tree as &$node) {
            if (! empty($node['blocks'])) {
                foreach ($node['blocks'] as &$block) {
                    if (($block['type'] ?? null) !== 'video') {
                        continue;
                    }
                    $payload = $block['payload'] ?? [];
                    $mediaId = $payload['media_id'] ?? null;
                    if (($payload['src_type'] ?? null) !== 'hls' && $mediaId) {
                        $payload['src'] = route('portal.media.stream', ['media' => $mediaId]);
                        $block['payload'] = $payload;
                    }
                }
                unset($block);
            }

            if (! empty($node['children'])) {
                $node['children'] = $this->publicizeVideos($node['children']);
            }
        }
        unset($node);

        return $tree;
    }

    /** The course's live publication, or a 404 if it isn't publicly accessible. */
    private function publication(Course $course): CoursePublication
    {
        if (! $this->gate->accessible($course)) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $course->latestPublication()->firstOrFail();
    }

    /**
     * Ids of lessons past the free-preview limit (reading order), for marking the
     * outline. Empty when there is no limit.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return list<string>
     */
    private function lockedIds(array $tree, ?int $free): array
    {
        if ($free === null) {
            return [];
        }

        $seen = 0;
        $ids = [];
        $walk = function (array $nodes) use (&$walk, &$seen, &$ids, $free): void {
            foreach ($nodes as $n) {
                if (LessonCounter::isLesson($n)) {
                    $seen++;
                    if ($seen > $free && ! empty($n['id'])) {
                        $ids[] = $n['id'];
                    }
                } else {
                    $walk($n['children'] ?? []);
                }
            }
        };
        $walk($tree);

        return $ids;
    }

    /**
     * The tree stripped of blocks — titles, labels and structure only — for a
     * lightweight landing outline, with a `locked` flag on gated lessons.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @param  list<string>  $locked
     * @return array<int, array<string, mixed>>
     */
    private function outline(array $tree, array $locked): array
    {
        return array_map(fn (array $n) => [
            'id' => $n['id'] ?? null,
            'title' => $n['title'] ?? '',
            'label' => $n['label'] ?? null,
            'number' => $n['number'] ?? null,
            'summary' => $n['summary'] ?? null,
            'has_content' => ! empty($n['blocks']),
            'locked' => in_array($n['id'] ?? null, $locked, true),
            'children' => $this->outline($n['children'] ?? [], $locked),
        ], $tree);
    }
}
