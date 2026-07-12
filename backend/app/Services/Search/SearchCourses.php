<?php

namespace App\Services\Search;

use App\Entitlements\EntitlementResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Search over the course tree, scoped by entitlement.
 *
 * A search endpoint that returns node titles from courses the caller cannot read
 * is a content leak: "Chapter 7: Kinematics of Rigid Bodies" *is* the product.
 * So the candidate set is the resolver's answer and the query filters to it —
 * never the other way round.
 *
 * Postgres FTS over the generated `search_vector`, with pg_trgm as a fallback so
 * "kinemtics" still finds Kinematics. Reach for Meilisearch when this visibly
 * fails you, not before.
 */
final class SearchCourses
{
    private const SIMILARITY_THRESHOLD = 0.3;

    public function __construct(private readonly EntitlementResolver $resolver) {}

    /**
     * @return list<array{
     *     node_id: string, title: string, summary: string|null,
     *     course: array{id: string, title: string}, rank: float
     * }>
     */
    public function handle(User $user, string $query, ?string $clientId = null, int $limit = 25): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $courseIds = $this->resolver->coursesFor($user, $clientId)->pluck('id');

        if ($courseIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('course_nodes as n')
            ->join('courses as c', 'c.id', '=', 'n.course_id')
            ->whereIn('n.course_id', $courseIds)
            ->whereNull('n.deleted_at')
            ->where(function ($q) use ($query) {
                $q->whereRaw('n.search_vector @@ websearch_to_tsquery(?, ?)', ['english', $query])
                    ->orWhereRaw('similarity(n.title, ?) > ?', [$query, self::SIMILARITY_THRESHOLD]);
            })
            ->selectRaw(
                "n.id, n.title, n.summary, n.course_id, c.title as course_title,
                 GREATEST(
                     ts_rank(n.search_vector, websearch_to_tsquery('english', ?)),
                     similarity(n.title, ?)
                 ) AS rank",
                [$query, $query],
            )
            ->orderByDesc('rank')
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row) => [
            'node_id' => (string) $row->id,
            'title' => (string) $row->title,
            'summary' => $row->summary === null ? null : (string) $row->summary,
            'course' => ['id' => (string) $row->course_id, 'title' => (string) $row->course_title],
            'rank' => round((float) $row->rank, 4),
        ])->all();
    }
}
