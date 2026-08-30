<?php

namespace App\Portal;

/**
 * Counts the "lessons" in a published snapshot tree — the things a learner opens,
 * matching the portal's Learn view. A lesson is a content-bearing node whose
 * children are its steps (no grandchildren); a container (with grandchildren) is
 * descended into, not counted. Keep in step with resources/js/portal/lib/lesson.ts.
 */
class LessonCounter
{
    /** @param array<int, array<string, mixed>> $tree */
    public static function count(array $tree): int
    {
        $count = 0;

        foreach ($tree as $node) {
            if (self::isLesson($node)) {
                $count++;
            } else {
                $count += self::count($node['children'] ?? []);
            }
        }

        return $count;
    }

    /** A content-bearing node whose children (if any) are its steps — not a container. */
    public static function isLesson(array $node): bool
    {
        $children = $node['children'] ?? [];
        $childHasContent = false;
        foreach ($children as $child) {
            if (! empty($child['children'])) {
                return false; // has grandchildren → a container, not a lesson
            }
            if (! empty($child['blocks'])) {
                $childHasContent = true;
            }
        }

        return ! empty($node['blocks']) || $childHasContent;
    }
}
