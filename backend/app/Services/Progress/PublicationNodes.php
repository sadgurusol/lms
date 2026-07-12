<?php

namespace App\Services\Progress;

use App\Models\CoursePublication;

/**
 * Reads the frozen snapshot rather than `course_nodes`.
 *
 * The draft tree has moved on; the learner is reading a publication. Asking
 * `course_nodes` whether a node exists would reject progress for a node the
 * author has since deleted, which the learner is nonetheless looking at.
 */
final class PublicationNodes
{
    /** @var array<string, array<string, bool>> keyed by publication id */
    private array $ids = [];

    /** @var array<string, list<string>> */
    private array $trackable = [];

    public function contains(CoursePublication $publication, string $nodeId): bool
    {
        return isset($this->index($publication)[$nodeId]);
    }

    /**
     * Nodes that count toward completion: those carrying content.
     *
     * A Part that only groups Chapters is not something a learner "completes";
     * counting it would make a course look 30% done before anything was read.
     *
     * @return list<string>
     */
    public function trackable(CoursePublication $publication): array
    {
        if (! isset($this->trackable[$publication->id])) {
            $this->index($publication);
        }

        return $this->trackable[$publication->id];
    }

    /** @return array<string, bool> */
    private function index(CoursePublication $publication): array
    {
        if (isset($this->ids[$publication->id])) {
            return $this->ids[$publication->id];
        }

        $ids = [];
        $trackable = [];

        $this->walk($publication->snapshot['tree'] ?? [], $ids, $trackable);

        $this->trackable[$publication->id] = $trackable;

        return $this->ids[$publication->id] = $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $branch
     * @param  array<string, bool>  $ids
     * @param  list<string>  $trackable
     */
    private function walk(array $branch, array &$ids, array &$trackable): void
    {
        foreach ($branch as $node) {
            $id = (string) $node['id'];
            $ids[$id] = true;

            if ($node['blocks'] !== []) {
                $trackable[] = $id;
            }

            $this->walk($node['children'], $ids, $trackable);
        }
    }
}
