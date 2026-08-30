import type { Block } from '@/studio/components/BlockView';
import type { ContentNode } from '../api';

export type PlayerStep = { id: string; title: string; blocks: Block[] };

/** Whether a learner can open this node to learn (it, or its direct children, carry content). */
export function isPlayable(node: ContentNode): boolean {
    if ((node.blocks?.length ?? 0) > 0) return true;
    return (node.children ?? []).some((k) => (k.blocks?.length ?? 0) > 0);
}

/**
 * The steps a lesson node plays. In the animated-builder shape a lesson's steps
 * are its child nodes; otherwise the node itself is a single step.
 */
export function lessonSteps(node: ContentNode): PlayerStep[] {
    const stepKids = (node.children ?? []).filter((k) => (k.blocks?.length ?? 0) > 0);
    if (stepKids.length) {
        return stepKids.map((k) => ({ id: k.id, title: k.title, blocks: k.blocks ?? [] }));
    }
    return [{ id: node.id, title: node.title, blocks: node.blocks ?? [] }];
}
