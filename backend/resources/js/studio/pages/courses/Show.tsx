import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { BlockView, type Block } from '@/studio/components/BlockView';
import { useConfirm } from '@/studio/components/ConfirmDialog';
import LessonPlayer from '@/studio/components/LessonPlayer';

type AddLevel = { schema_level_id: string; name: string; remaining: number | null };

type TreeNode = {
    id: string;
    title: string;
    level_name: string;
    allows_content: boolean;
    block_count: number;
    allows_assessment: boolean;
    assessment_count: number;
    blocks: Block[];
    children: TreeNode[];
    add_levels: AddLevel[];
};

type Props = {
    course: {
        id: string;
        title: string;
        code: string | null;
        workflow_state: string;
        published_number: number | null;
        schema: { name: string; version: number };
    };
    tree: TreeNode[];
    root_levels: AddLevel[];
    can: { edit: boolean; revise: boolean };
};

const only = { preserveScroll: true, preserveState: false } as const;

export default function CourseShow({ course, tree, root_levels, can }: Props) {
    return (
        <StudioLayout title={course.title}>
            <Head title={course.title} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href="/studio/courses" className="hover:underline">
                    ← Courses
                </Link>
                <span aria-hidden>·</span>
                <span>
                    {course.schema.name} · v{course.schema.version}
                </span>
                <span aria-hidden>·</span>
                <span>{course.workflow_state.replace('_', ' ')}</span>

                <Link
                    href={`/studio/courses/${course.id}/insights`}
                    className="ml-auto rounded-md border border-zinc-300 px-3 py-1.5 font-medium text-zinc-700 hover:border-indigo-400 hover:text-indigo-600 dark:border-zinc-700 dark:text-zinc-300"
                >
                    Insights
                </Link>
                <Link
                    href={`/studio/courses/${course.id}/team`}
                    className="rounded-md border border-zinc-300 px-3 py-1.5 font-medium text-zinc-700 hover:border-indigo-400 hover:text-indigo-600 dark:border-zinc-700 dark:text-zinc-300"
                >
                    Team
                </Link>
                <Link
                    href={`/studio/courses/${course.id}/preview`}
                    className="rounded-md border border-zinc-300 px-3 py-1.5 font-medium text-zinc-700 hover:border-indigo-400 hover:text-indigo-600 dark:border-zinc-700 dark:text-zinc-300"
                >
                    Preview as learner
                </Link>
                {/* Nothing to review or publish on a frozen version. Reopen it as
                    a new draft first (the read-only banner offers that). */}
                {course.workflow_state !== 'published' && (
                    <Link
                        href={`/studio/courses/${course.id}/review`}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-500"
                    >
                        Review &amp; publish
                    </Link>
                )}
            </div>

            {/* The live published version is always one click away, read-only,
                whatever state the draft is in. */}
            {course.published_number !== null && (
                <div className="mb-4 flex items-center gap-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm dark:border-emerald-900 dark:bg-emerald-950">
                    <span className="font-medium text-emerald-900 dark:text-emerald-100">
                        Live: published version {course.published_number}
                    </span>
                    <Link
                        href={`/studio/courses/${course.id}/published`}
                        className="text-emerald-800 underline hover:text-emerald-600 dark:text-emerald-200"
                    >
                        View (read-only)
                    </Link>
                </div>
            )}

            {course.workflow_state === 'published' ? (
                <div className="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-4 dark:border-emerald-900 dark:bg-emerald-950">
                    <p className="font-medium text-emerald-900 dark:text-emerald-100">
                        This version is read-only.
                    </p>
                    <p className="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
                        There is no draft in progress — the course matches published version{' '}
                        {course.published_number}. Start a new version to create a draft you can edit;
                        learners keep seeing version {course.published_number} until you publish it.
                    </p>
                    {can.revise && (
                        <button
                            type="button"
                            onClick={() => router.post(`/studio/courses/${course.id}/revise`)}
                            className="mt-3 rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500"
                        >
                            Start new version
                        </button>
                    )}
                </div>
            ) : course.published_number !== null ? (
                <div className="mb-6 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 dark:border-indigo-900 dark:bg-indigo-950 dark:text-indigo-100">
                    You are editing a <strong>new draft</strong> (will become version{' '}
                    {course.published_number + 1} when published). Published version{' '}
                    {course.published_number} stays live and untouched until then.
                </div>
            ) : (
                !can.edit && (
                    <p className="mb-6 rounded-md border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
                        You can view this course but not edit its structure.
                    </p>
                )
            )}

            {can.edit && course.workflow_state === 'approved' && (
                <div className="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    This course is approved and ready to publish. Editing it now will send it back to
                    draft and require a fresh review.
                </div>
            )}

            {tree.length === 0 && (
                <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
                    This course has no content yet. Add a top-level section to begin.
                </p>
            )}

            <Branch
                nodes={tree}
                siblings={tree}
                courseId={course.id}
                editable={can.edit}
            />

            {can.edit && (
                <div className="mt-4">
                    <AddMenu courseId={course.id} parentId={null} levels={root_levels} />
                </div>
            )}
        </StudioLayout>
    );
}

function Branch({
    nodes,
    siblings,
    courseId,
    editable,
}: {
    nodes: TreeNode[];
    siblings: TreeNode[];
    courseId: string;
    editable: boolean;
}) {
    if (nodes.length === 0) return null;

    return (
        <ul className="space-y-2">
            {nodes.map((node, index) => (
                <NodeRow
                    key={node.id}
                    node={node}
                    siblings={siblings}
                    index={index}
                    courseId={courseId}
                    editable={editable}
                />
            ))}
        </ul>
    );
}

function NodeRow({
    node,
    siblings,
    index,
    courseId,
    editable,
}: {
    node: TreeNode;
    siblings: TreeNode[];
    index: number;
    courseId: string;
    editable: boolean;
}) {
    const [renaming, setRenaming] = useState(false);
    const [showContent, setShowContent] = useState(false);
    const [showPlayer, setShowPlayer] = useState(false);
    const confirm = useConfirm();

    const isFirst = index === 0;
    const isLast = index === siblings.length - 1;
    // A lesson: its children are content leaves (Steps). Play it as a learner.
    const isPlayableLesson = node.children.length > 0 && node.children.every((c) => c.children.length === 0 && c.allows_content);

    function move(direction: 'up' | 'down') {
        // after_node_id is the sibling this node should follow once moved.
        const afterId =
            direction === 'up'
                ? (siblings[index - 2]?.id ?? null)
                : (siblings[index + 1]?.id ?? null);

        router.post(`/studio/course-nodes/${node.id}/move`, { after_node_id: afterId }, only);
    }

    async function destroy() {
        const ok = await confirm({
            title: 'Remove section',
            message: (
                <>
                    Remove <strong>{node.title}</strong> and everything beneath it? This cannot be undone.
                </>
            ),
            confirmLabel: 'Remove',
            danger: true,
        });
        if (ok) router.delete(`/studio/course-nodes/${node.id}`, only);
    }

    return (
        <li>
            <div className="flex items-center gap-3 rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900">
                <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {node.level_name}
                </span>

                {renaming ? (
                    <RenameForm node={node} onDone={() => setRenaming(false)} />
                ) : (
                    <span className="min-w-0 flex-1 truncate font-medium">{node.title}</span>
                )}

                {isPlayableLesson && !renaming && (
                    <button
                        type="button"
                        onClick={() => setShowPlayer(true)}
                        className="rounded px-2 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950"
                    >
                        ▶ Play
                    </button>
                )}

                {node.allows_content && !renaming && (
                    editable ? (
                        <Link
                            href={`/studio/course-nodes/${node.id}/content`}
                            className="rounded px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950"
                        >
                            {node.block_count} {node.block_count === 1 ? 'block' : 'blocks'} · edit content
                        </Link>
                    ) : (
                        // Read-only: view the content in place, no navigation.
                        <button
                            type="button"
                            onClick={() => setShowContent((v) => !v)}
                            aria-expanded={showContent}
                            className="rounded px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950"
                        >
                            {node.block_count} {node.block_count === 1 ? 'block' : 'blocks'} ·{' '}
                            {showContent ? 'hide' : 'view'}
                        </button>
                    )
                )}

                {node.allows_assessment && !renaming && (
                    <Link
                        href={`/studio/course-nodes/${node.id}/assessments`}
                        className="rounded px-2 py-1 text-xs text-violet-600 hover:bg-violet-50 dark:text-violet-400 dark:hover:bg-violet-950"
                    >
                        {node.assessment_count} {node.assessment_count === 1 ? 'quiz' : 'quizzes'} ·{' '}
                        {editable ? 'manage' : 'view'}
                    </Link>
                )}

                {editable && !renaming && (
                    <div className="flex items-center gap-1 text-xs">
                        <IconButton label="Move up" disabled={isFirst} onClick={() => move('up')}>
                            ↑
                        </IconButton>
                        <IconButton label="Move down" disabled={isLast} onClick={() => move('down')}>
                            ↓
                        </IconButton>
                        <button
                            type="button"
                            onClick={() => setRenaming(true)}
                            className="rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        >
                            Rename
                        </button>
                        <button
                            type="button"
                            onClick={destroy}
                            className="rounded px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                        >
                            Delete
                        </button>
                    </div>
                )}
            </div>

            {showPlayer && <LessonPlayer lessonNodeId={node.id} title={node.title} onClose={() => setShowPlayer(false)} />}

            {/* Read-only content, viewed in place. */}
            {showContent && !editable && (
                <div className="ml-6 mt-2 space-y-4 rounded-md border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    {node.blocks.length === 0 ? (
                        <p className="text-sm italic text-zinc-400">No content on this node.</p>
                    ) : (
                        node.blocks.map((block) => <BlockView key={block.id} block={block} />)
                    )}
                </div>
            )}

            {/* The subtree, plus this node's own "add child" menu. */}
            <div className="ml-6 mt-2 border-l border-zinc-200 pl-4 dark:border-zinc-800">
                <Branch
                    nodes={node.children}
                    siblings={node.children}
                    courseId={courseId}
                    editable={editable}
                />

                {editable && node.add_levels.length > 0 && (
                    <div className={node.children.length > 0 ? 'mt-2' : ''}>
                        <AddMenu courseId={courseId} parentId={node.id} levels={node.add_levels} />
                    </div>
                )}
            </div>
        </li>
    );
}

/**
 * A row of "+ Level" buttons. Clicking one opens an inline title field. A level
 * at zero remaining capacity is shown disabled — the server would refuse it, and
 * the reason (a max_occurrences on the schema) is not something to discover by
 * trying.
 */
function AddMenu({
    courseId,
    parentId,
    levels,
}: {
    courseId: string;
    parentId: string | null;
    levels: AddLevel[];
}) {
    const [adding, setAdding] = useState<AddLevel | null>(null);

    if (adding) {
        return (
            <AddForm
                courseId={courseId}
                parentId={parentId}
                level={adding}
                onDone={() => setAdding(null)}
            />
        );
    }

    return (
        <div className="flex flex-wrap gap-2">
            {levels.map((level) => {
                const full = level.remaining === 0;
                return (
                    <button
                        key={level.schema_level_id}
                        type="button"
                        disabled={full}
                        onClick={() => setAdding(level)}
                        title={full ? `The maximum number of ${level.name} has been reached.` : undefined}
                        className="rounded-md border border-dashed border-zinc-300 px-2.5 py-1 text-sm text-zinc-600 hover:border-indigo-400 hover:text-indigo-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700 dark:text-zinc-400"
                    >
                        + {level.name}
                    </button>
                );
            })}
        </div>
    );
}

function AddForm({
    courseId,
    parentId,
    level,
    onDone,
}: {
    courseId: string;
    parentId: string | null;
    level: AddLevel;
    onDone: () => void;
}) {
    const [title, setTitle] = useState('');
    const [saving, setSaving] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();
        setSaving(true);
        router.post(
            `/studio/courses/${courseId}/nodes`,
            { parent_id: parentId, schema_level_id: level.schema_level_id, title, after_node_id: null },
            { ...only, onFinish: () => setSaving(false), onSuccess: onDone },
        );
    }

    return (
        <form onSubmit={submit} className="flex items-center gap-2">
            <input
                autoFocus
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder={`New ${level.name} title`}
                className="w-64 rounded-md border border-zinc-300 px-2.5 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
            />
            <button
                type="submit"
                disabled={saving || title.trim() === ''}
                className="rounded-md bg-indigo-600 px-2.5 py-1 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                Add
            </button>
            <button type="button" onClick={onDone} className="rounded-md px-2 py-1 text-sm">
                Cancel
            </button>
        </form>
    );
}

function RenameForm({ node, onDone }: { node: TreeNode; onDone: () => void }) {
    const [title, setTitle] = useState(node.title);
    const [saving, setSaving] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();
        setSaving(true);
        router.patch(
            `/studio/course-nodes/${node.id}`,
            { title },
            { ...only, onFinish: () => setSaving(false), onSuccess: onDone },
        );
    }

    return (
        <form onSubmit={submit} className="flex min-w-0 flex-1 items-center gap-2">
            <input
                autoFocus
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                className="min-w-0 flex-1 rounded-md border border-zinc-300 px-2.5 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
            />
            <button
                type="submit"
                disabled={saving || title.trim() === ''}
                className="rounded-md bg-indigo-600 px-2.5 py-1 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                Save
            </button>
            <button type="button" onClick={onDone} className="rounded-md px-2 py-1 text-sm">
                Cancel
            </button>
        </form>
    );
}

function IconButton({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={onClick}
            className="rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-30 dark:text-zinc-400 dark:hover:bg-zinc-800"
        >
            {children}
        </button>
    );
}
