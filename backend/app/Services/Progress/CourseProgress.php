<?php

namespace App\Services\Progress;

use App\Models\CoursePublication;
use App\Models\NodeProgress;
use App\Models\User;

final class CourseProgress
{
    public function __construct(private readonly PublicationNodes $nodes) {}

    /**
     * Completion is measured against the publication the learner is reading, not
     * against the current draft tree.
     *
     * @return array<string, mixed>
     */
    public function summarise(User $user, CoursePublication $publication): array
    {
        $trackable = $this->nodes->trackable($publication);

        $rows = NodeProgress::query()
            ->where('user_id', $user->id)
            ->where('publication_id', $publication->id)
            ->get();

        // Not `$rows->keyBy('course_node_id')->only($trackable)`: an Eloquent
        // collection's only() filters by *model primary key*, ignoring the keys
        // keyBy() just assigned. It silently matches nothing.
        $trackableIds = array_flip($trackable);

        $completed = $rows
            ->filter(fn (NodeProgress $p) => isset($trackableIds[$p->course_node_id]) && $p->isCompleted())
            ->count();

        $total = count($trackable);

        return [
            'publication_id' => $publication->id,
            'publication_number' => $publication->number,
            'completed_nodes' => $completed,
            'total_nodes' => $total,
            // A course with nothing trackable is 0% done, not a division by zero.
            'percent' => $total === 0 ? 0.0 : round($completed / $total * 100, 1),
            'seconds_spent' => (int) $rows->sum('seconds_spent'),
            'nodes' => $rows->map(fn (NodeProgress $p) => [
                'node_id' => $p->course_node_id,
                'state' => $p->state,
                'seconds_spent' => $p->seconds_spent,
                'last_position' => $p->last_position,
                'completed_at' => $p->completed_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
