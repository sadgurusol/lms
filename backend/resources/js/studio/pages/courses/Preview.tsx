import { Head, Link } from '@inertiajs/react';
import { BlockView, type Block } from '@/studio/components/BlockView';

/* ----------------------------------------------------------------------------
 * A read-only render of a course, from the same snapshot structure that ships to
 * learners. Draft (work in progress) or published (frozen, live) — both use it.
 * ------------------------------------------------------------------------- */

type SnapshotNode = {
    id: string;
    title: string;
    number: string;
    label: string;
    summary: string | null;
    blocks: Block[];
    children: SnapshotNode[];
};

type Context = {
    kind: 'draft' | 'published';
    version: number | null;
    workflow_state: string;
};

type Props = {
    snapshot: {
        course: { title: string; code: string | null; subject: string | null; grade_band: string | null };
        tree: SnapshotNode[];
    };
    context: Context;
};

export default function CoursePreview({ snapshot, context }: Props) {
    const { course, tree } = snapshot;

    const isPublished = context.kind === 'published';
    const badge = isPublished
        ? `Published version ${context.version} · live`
        : `Draft preview · ${context.workflow_state.replace('_', ' ')}`;
    const badgeClass = isPublished
        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
        : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200';

    return (
        <div className="min-h-full bg-zinc-50 dark:bg-zinc-950">
            <Head title={`${isPublished ? 'Published' : 'Preview'} · ${course.title}`} />

            <div className="border-b border-zinc-200 bg-white px-6 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div className="mx-auto flex max-w-3xl items-center gap-3">
                    <Link href="/studio/courses" className="text-zinc-600 hover:underline dark:text-zinc-400">
                        ← Studio
                    </Link>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${badgeClass}`}>{badge}</span>
                    {isPublished && (
                        <span className="text-xs text-zinc-500 dark:text-zinc-400">
                            Read-only. This is exactly what learners see.
                        </span>
                    )}
                </div>
            </div>

            <article className="mx-auto max-w-3xl px-6 py-10">
                <header className="mb-10 border-b border-zinc-200 pb-6 dark:border-zinc-800">
                    <h1 className="text-3xl font-bold tracking-tight">{course.title}</h1>
                    <p className="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        {[course.code, course.subject, course.grade_band].filter(Boolean).join(' · ')}
                    </p>
                </header>

                {tree.length === 0 ? (
                    <p className="text-zinc-500 dark:text-zinc-400">This course has no content yet.</p>
                ) : (
                    tree.map((node) => <NodeSection key={node.id} node={node} depth={0} />)
                )}
            </article>
        </div>
    );
}

function NodeSection({ node, depth }: { node: SnapshotNode; depth: number }) {
    const Heading = (['h2', 'h3', 'h4', 'h5'][Math.min(depth, 3)] ?? 'h6') as 'h2';
    const size = ['text-2xl', 'text-xl', 'text-lg', 'text-base'][Math.min(depth, 3)];

    return (
        <section className={depth > 0 ? 'mt-8 border-l border-zinc-200 pl-5 dark:border-zinc-800' : 'mt-10'}>
            <Heading className={`${size} font-semibold tracking-tight`}>{node.label || node.title}</Heading>

            {node.summary && <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{node.summary}</p>}

            {node.blocks.length > 0 && (
                <div className="mt-4 space-y-4">
                    {node.blocks.map((block) => (
                        <BlockView key={block.id} block={block} />
                    ))}
                </div>
            )}

            {node.children.map((child) => (
                <NodeSection key={child.id} node={child} depth={depth + 1} />
            ))}
        </section>
    );
}
