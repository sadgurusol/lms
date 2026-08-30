<?php

namespace App\Portal;

/**
 * Applies a free-preview limit to a published snapshot tree: the first N lessons
 * (in reading order) keep their content; every lesson after that is marked
 * `locked` and has its content stripped, so gated material never leaves the
 * server. Container nodes are traversed, not counted. NULL limit = all free.
 */
class PreviewGate
{
    /**
     * @param  array<int, array<string, mixed>>  $tree
     * @return array{0: array<int, array<string, mixed>>, 1: int}  [gatedTree, lockedCount]
     */
    public static function apply(array $tree, ?int $freeLimit): array
    {
        if ($freeLimit === null || $freeLimit <= 0) {
            return [$tree, 0];
        }

        $seen = 0;
        $locked = 0;

        $walk = function (array $nodes) use (&$walk, &$seen, &$locked, $freeLimit): array {
            $out = [];
            foreach ($nodes as $node) {
                if (LessonCounter::isLesson($node)) {
                    $seen++;
                    if ($seen > $freeLimit) {
                        $locked++;
                        $node['locked'] = true;
                        $node['blocks'] = [];
                        $node['children'] = array_map(function (array $child): array {
                            $child['blocks'] = [];

                            return $child;
                        }, $node['children'] ?? []);
                    }
                    $out[] = $node;
                } else {
                    $node['children'] = $walk($node['children'] ?? []);
                    $out[] = $node;
                }
            }

            return $out;
        };

        return [$walk($tree), $locked];
    }
}
