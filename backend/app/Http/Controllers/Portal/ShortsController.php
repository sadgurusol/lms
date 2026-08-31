<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\PortalShortStat;
use App\Portal\CourseGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public "shorts": each animated step of a public course is a self-contained
 * ~30–60s reveal that plays in the portal's vertical shorts feed, with a link to
 * the full course. Views are counted per short (course + node).
 */
class ShortsController extends Controller
{
    private const MAX = 60;

    public function __construct(private readonly CourseGate $gate) {}

    /** A shuffled batch of shorts across every public course. `focus` (a node id,
     *  from a shared link) is guaranteed first so the feed opens on it. */
    public function index(Request $request): JsonResponse
    {
        $shorts = [];

        $courses = $this->gate->listable()
            ->with(['latestPublication' => fn ($q) => $q->select('id', 'course_id', 'snapshot')])
            ->get(['id', 'slug', 'title', 'subject', 'latest_publication_id']);

        foreach ($courses as $course) {
            $tree = $course->latestPublication->snapshot['tree'] ?? [];
            $this->collect($tree, $course, $shorts);
        }

        $focus = (string) $request->query('focus', '');
        $focused = null;
        if ($focus !== '') {
            $idx = array_search($focus, array_column($shorts, 'node_id'), true);
            if ($idx !== false) {
                $focused = $shorts[$idx];
                unset($shorts[$idx]);
                $shorts = array_values($shorts);
            }
        }

        shuffle($shorts);
        if ($focused !== null) {
            array_unshift($shorts, $focused);
        }
        $shorts = array_slice($shorts, 0, self::MAX);

        // Attach view counts in one query.
        $views = PortalShortStat::query()
            ->whereIn('node_id', array_column($shorts, 'node_id'))
            ->pluck('views', 'node_id');
        foreach ($shorts as &$s) {
            $s['views'] = (int) ($views[$s['node_id']] ?? 0);
        }

        return response()->json(['data' => array_values($shorts)]);
    }

    /** Record one view of a short (anonymous, best-effort). */
    public function view(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:200'],
            'node_id' => ['required', 'uuid'],
        ]);

        $course = $this->gate->accessibleQuery()
            ->where(function ($q) use ($data) {
                $q->where('slug', $data['slug']);
                if (Str::isUuid($data['slug'])) {
                    $q->orWhere('id', $data['slug']);
                }
            })
            ->first(['id']);

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        // Upsert-increment: one row per (course, node).
        DB::statement(
            <<<'SQL'
            INSERT INTO portal_short_stats (id, course_id, node_id, views, created_at, updated_at)
            VALUES (?, ?, ?, 1, now(), now())
            ON CONFLICT (course_id, node_id)
            DO UPDATE SET views = portal_short_stats.views + 1, updated_at = now()
            SQL,
            [(string) Str::uuid(), $course->id, $data['node_id']],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Collect animated-reveal steps as shorts.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $out
     */
    private function collect(array $nodes, Course $course, array &$out): void
    {
        foreach ($nodes as $node) {
            foreach ($node['blocks'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'animated_reveal' && ! empty($block['payload']['fragments'])) {
                    $out[] = [
                        'node_id' => $node['id'],
                        'course_slug' => $course->slug ?: $course->id,
                        'course_title' => $course->title,
                        'subject' => $course->subject,
                        'title' => $node['title'] ?? '',
                        'fragments' => $block['payload']['fragments'],
                    ];
                }
            }
            $this->collect($node['children'] ?? [], $course, $out);
        }
    }
}
