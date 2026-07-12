import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type AssessmentRow = {
    id: string;
    kind: string;
    title: string;
    question_count: number;
    total_points: number;
};

type Props = {
    node: { id: string; title: string; level_name: string; allows_assessment: boolean };
    course: { id: string; title: string };
    assessments: AssessmentRow[];
    can: { manage: boolean };
};

export default function AssessmentsIndex({ node, course, assessments, can }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <StudioLayout title={`${node.title} · assessments`}>
            <Head title={`${node.title} · assessments`} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href={`/studio/courses/${course.id}`} className="hover:underline">
                    ← {course.title}
                </Link>
                <span aria-hidden>·</span>
                <span>
                    {node.level_name}: {node.title}
                </span>
            </div>

            {!node.allows_assessment ? (
                <p className="rounded-md border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
                    This level does not allow assessments.
                </p>
            ) : (
                <>
                    {can.manage && !creating && (
                        <button
                            type="button"
                            onClick={() => setCreating(true)}
                            className="mb-6 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            New assessment
                        </button>
                    )}

                    {creating && <CreateForm nodeId={node.id} onCancel={() => setCreating(false)} />}

                    {assessments.length === 0 ? (
                        <p className="text-sm text-zinc-500 dark:text-zinc-400">No assessments yet.</p>
                    ) : (
                        <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                            {assessments.map((a) => (
                                <li key={a.id} className="flex items-center gap-4 px-4 py-3">
                                    <span
                                        className={`rounded px-1.5 py-0.5 text-xs font-medium ${
                                            a.kind === 'test'
                                                ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'
                                                : 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200'
                                        }`}
                                    >
                                        {a.kind}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <Link
                                            href={`/studio/assessments/${a.id}`}
                                            className="font-medium hover:text-indigo-600 hover:underline dark:hover:text-indigo-400"
                                        >
                                            {a.title}
                                        </Link>
                                        <p className="text-sm text-zinc-500 dark:text-zinc-400">
                                            {a.question_count}{' '}
                                            {a.question_count === 1 ? 'question' : 'questions'} · {a.total_points}{' '}
                                            {a.total_points === 1 ? 'point' : 'points'}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </StudioLayout>
    );
}

function CreateForm({ nodeId, onCancel }: { nodeId: string; onCancel: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ kind: 'quiz', title: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        post(`/studio/course-nodes/${nodeId}/assessments`);
    }

    return (
        <form
            onSubmit={submit}
            className="mb-6 max-w-xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="a-kind">
                    Type
                </label>
                <select
                    id="a-kind"
                    value={data.kind}
                    onChange={(e) => setData('kind', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <option value="quiz">Quiz — formative, low stakes (unlimited attempts, no timer)</option>
                    <option value="test">Test — graded (one attempt, timed, pass mark)</option>
                </select>
                <p className="text-xs text-zinc-500 dark:text-zinc-400">
                    These are only defaults; you can change every rule afterwards.
                </p>
            </div>

            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="a-title">
                    Title
                </label>
                <input
                    id="a-title"
                    autoFocus
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="End of chapter test"
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                {errors.title && <p className="text-sm text-red-600">{errors.title}</p>}
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Create
                </button>
                <button type="button" onClick={onCancel} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}
