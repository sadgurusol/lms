import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { bodyToText } from '@/studio/lib/portableText';

type Settings = {
    time_limit_s: number | null;
    max_attempts: number | null;
    pass_percentage: number | null;
    shuffle_questions: boolean;
    shuffle_options: boolean;
    show_answers: string;
    allow_backtrack: boolean;
    counts_toward_progress: boolean;
    question_pool_size: number | null;
};

type AQ = { id: string; question_id: string; type: string; stem: { body?: unknown }; points: number };
type Available = { id: string; type: string; stem: { body?: unknown }; default_points: number; bank: string };

type Props = {
    assessment: {
        id: string;
        kind: string;
        title: string;
        total_points: number;
        node_id: string;
        settings: Settings;
    };
    course: { id: string; title: string };
    questions: AQ[];
    available: Available[];
    can: { manage: boolean };
};

const only = { preserveScroll: true, preserveState: false } as const;

export default function AssessmentShow({ assessment, questions, available, can }: Props) {
    return (
        <StudioLayout title={assessment.title}>
            <Head title={assessment.title} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href={`/studio/course-nodes/${assessment.node_id}/assessments`} className="hover:underline">
                    ← Assessments
                </Link>
                <span aria-hidden>·</span>
                <span className="capitalize">{assessment.kind}</span>
                <span aria-hidden>·</span>
                <span>{assessment.total_points} points</span>
            </div>

            {!can.manage && (
                <p className="mb-6 rounded-md border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
                    This assessment is read-only (the course is published, or you may not edit it).
                </p>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Questions
                    </h2>
                    {questions.length === 0 ? (
                        <p className="text-sm text-zinc-500 dark:text-zinc-400">
                            No questions yet. Add some from the panel on the right.
                        </p>
                    ) : (
                        <ol className="space-y-2">
                            {questions.map((q, i) => (
                                <QuestionRow
                                    key={q.id}
                                    aq={q}
                                    index={i}
                                    siblings={questions}
                                    canManage={can.manage}
                                />
                            ))}
                        </ol>
                    )}
                </div>

                <div className="space-y-6">
                    {can.manage && <SettingsForm assessment={assessment} />}
                    {can.manage && <Picker assessmentId={assessment.id} available={available} />}
                </div>
            </div>
        </StudioLayout>
    );
}

function QuestionRow({
    aq,
    index,
    siblings,
    canManage,
}: {
    aq: AQ;
    index: number;
    siblings: AQ[];
    canManage: boolean;
}) {
    const [points, setPoints] = useState(aq.points);

    function move(dir: 'up' | 'down') {
        const afterId = dir === 'up' ? (siblings[index - 2]?.id ?? null) : (siblings[index + 1]?.id ?? null);
        router.post(`/studio/assessment-questions/${aq.id}/move`, { after_id: afterId }, only);
    }

    return (
        <li className="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div className="flex items-start gap-3">
                <span className="mt-0.5 text-xs font-medium text-zinc-400">Q{index + 1}</span>
                <p className="min-w-0 flex-1 whitespace-pre-wrap text-sm">
                    {bodyToText(aq.stem.body) || <em className="text-zinc-400">No stem</em>}
                </p>
                {canManage && (
                    <div className="flex shrink-0 items-center gap-1 text-xs">
                        <button aria-label="Move up" disabled={index === 0} onClick={() => move('up')} className="rounded px-1.5 py-1 disabled:opacity-30 hover:bg-zinc-100 dark:hover:bg-zinc-800">↑</button>
                        <button aria-label="Move down" disabled={index === siblings.length - 1} onClick={() => move('down')} className="rounded px-1.5 py-1 disabled:opacity-30 hover:bg-zinc-100 dark:hover:bg-zinc-800">↓</button>
                        <button
                            onClick={() => router.delete(`/studio/assessment-questions/${aq.id}`, only)}
                            className="rounded px-1.5 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                        >
                            Remove
                        </button>
                    </div>
                )}
            </div>

            <div className="mt-2 flex items-center gap-2 pl-7 text-xs">
                <span className="rounded bg-zinc-100 px-1.5 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {aq.type}
                </span>
                {canManage ? (
                    <span className="flex items-center gap-1">
                        <input
                            value={points}
                            onChange={(e) => setPoints(Number(e.target.value))}
                            onBlur={() => {
                                if (points !== aq.points && points > 0)
                                    router.patch(`/studio/assessment-questions/${aq.id}`, { points }, only);
                            }}
                            className="w-14 rounded-md border border-zinc-300 px-1.5 py-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                            inputMode="decimal"
                            aria-label="Points"
                        />
                        points
                    </span>
                ) : (
                    <span className="text-zinc-500">{aq.points} points</span>
                )}
            </div>
        </li>
    );
}

function Picker({ assessmentId, available }: { assessmentId: string; available: Available[] }) {
    const [query, setQuery] = useState('');
    const filtered = available.filter((q) => bodyToText(q.stem.body).toLowerCase().includes(query.toLowerCase()));

    function add(questionId: string) {
        router.post(`/studio/assessments/${assessmentId}/questions`, { question_id: questionId }, { preserveScroll: true });
    }

    return (
        <section className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Add from banks
            </h2>
            {available.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">
                    No more questions available. Author some in a{' '}
                    <Link href="/studio/questions" className="text-indigo-600 underline">
                        question bank
                    </Link>
                    .
                </p>
            ) : (
                <>
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Filter…"
                        className="mb-3 w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
                    />
                    <ul className="max-h-96 space-y-2 overflow-y-auto">
                        {filtered.map((q) => (
                            <li key={q.id} className="flex items-start gap-2 border-b border-zinc-100 pb-2 text-sm dark:border-zinc-800">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate">{bodyToText(q.stem.body) || <em className="text-zinc-400">No stem</em>}</p>
                                    <p className="text-xs text-zinc-400">
                                        {q.type} · {q.bank}
                                    </p>
                                </div>
                                <button
                                    onClick={() => add(q.id)}
                                    className="shrink-0 rounded-md border border-indigo-300 px-2 py-0.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950"
                                >
                                    Add
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

function SettingsForm({ assessment }: { assessment: Props['assessment'] }) {
    const { data, setData, patch, processing } = useForm({
        title: assessment.title,
        ...assessment.settings,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        patch(`/studio/assessments/${assessment.id}`, { preserveScroll: true });
    }

    // A nullable number field: empty string clears it (unlimited / no timer).
    const numOrNull = (v: string) => (v === '' ? null : Number(v));

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Rules</h2>

            <Field label="Title">
                <input value={data.title} onChange={(e) => setData('title', e.target.value)} className={input} />
            </Field>

            <Field label="Time limit (seconds, blank = none)">
                <input
                    value={data.time_limit_s ?? ''}
                    onChange={(e) => setData('time_limit_s', numOrNull(e.target.value))}
                    className={input}
                    inputMode="numeric"
                />
            </Field>

            <Field label="Max attempts (blank = unlimited)">
                <input
                    value={data.max_attempts ?? ''}
                    onChange={(e) => setData('max_attempts', numOrNull(e.target.value))}
                    className={input}
                    inputMode="numeric"
                />
            </Field>

            <Field label="Pass mark % (blank = not graded)">
                <input
                    value={data.pass_percentage ?? ''}
                    onChange={(e) => setData('pass_percentage', numOrNull(e.target.value))}
                    className={input}
                    inputMode="decimal"
                />
            </Field>

            <Field label="Show answers">
                <select value={data.show_answers} onChange={(e) => setData('show_answers', e.target.value)} className={input}>
                    <option value="never">Never</option>
                    <option value="after_submit">After submitting</option>
                    <option value="after_pass">After passing</option>
                </select>
            </Field>

            <Toggle label="Shuffle questions" checked={data.shuffle_questions} onChange={(v) => setData('shuffle_questions', v)} />
            <Toggle label="Shuffle options" checked={data.shuffle_options} onChange={(v) => setData('shuffle_options', v)} />
            <Toggle label="Allow going back" checked={data.allow_backtrack} onChange={(v) => setData('allow_backtrack', v)} />
            <Toggle label="Counts toward progress" checked={data.counts_toward_progress} onChange={(v) => setData('counts_toward_progress', v)} />

            <button
                type="submit"
                disabled={processing}
                className="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                Save rules
            </button>
        </form>
    );
}

const input = 'w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <label className="block text-xs font-medium text-zinc-600 dark:text-zinc-400">{label}</label>
            {children}
        </div>
    );
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            {label}
        </label>
    );
}
