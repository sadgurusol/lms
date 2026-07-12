import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';
import { bodyToText } from '@/studio/lib/portableText';

type Option = { text: string; correct: boolean };

type Question = {
    id: string;
    type: string;
    stem: { body?: unknown };
    explanation: { body?: unknown } | null;
    points: number;
    grading: Record<string, unknown>;
    options: Option[];
};

type Props = {
    bank: { id: string; name: string; course: { id: string; title: string } | null };
    questions: Question[];
    can: { manage: boolean };
};

// Only the types the studio can author. Others need richer editors.
const TYPES: { value: string; label: string }[] = [
    { value: 'mcq_single', label: 'Multiple choice (one answer)' },
    { value: 'mcq_multi', label: 'Multiple choice (many answers)' },
    { value: 'true_false', label: 'True / false' },
    { value: 'numeric', label: 'Numeric' },
    { value: 'short_answer', label: 'Short answer' },
    { value: 'essay', label: 'Essay (human-graded)' },
];

const TYPE_LABEL = Object.fromEntries(TYPES.map((t) => [t.value, t.label]));

export default function QuestionBankShow({ bank, questions, can }: Props) {
    const [adding, setAdding] = useState<string | null>(null);
    const [editingId, setEditingId] = useState<string | null>(null);

    return (
        <StudioLayout title={bank.name}>
            <Head title={bank.name} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href="/studio/questions" className="hover:underline">
                    ← Question banks
                </Link>
                <span aria-hidden>·</span>
                <span>{bank.course ? `Course: ${bank.course.title}` : 'Global'}</span>
            </div>

            {can.manage && (
                <div className="mb-6 flex items-center gap-2">
                    {adding === null ? (
                        <div className="flex items-center gap-2">
                            <select
                                value=""
                                onChange={(e) => e.target.value && setAdding(e.target.value)}
                                className="rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                            >
                                <option value="">+ Add question…</option>
                                {TYPES.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ) : (
                        <QuestionForm
                            bankId={bank.id}
                            type={adding}
                            onDone={() => setAdding(null)}
                        />
                    )}
                </div>
            )}

            {questions.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No questions yet.</p>
            ) : (
                <ol className="space-y-3">
                    {questions.map((q, i) => (
                        <li
                            key={q.id}
                            className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            {editingId === q.id ? (
                                <QuestionForm
                                    bankId={bank.id}
                                    type={q.type}
                                    question={q}
                                    onDone={() => setEditingId(null)}
                                />
                            ) : (
                                <QuestionCard
                                    q={q}
                                    index={i}
                                    canManage={can.manage}
                                    onEdit={() => setEditingId(q.id)}
                                />
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </StudioLayout>
    );
}

function QuestionCard({
    q,
    index,
    canManage,
    onEdit,
}: {
    q: Question;
    index: number;
    canManage: boolean;
    onEdit: () => void;
}) {
    const confirm = useConfirm();

    async function remove() {
        const ok = await confirm({
            title: 'Remove question',
            message: 'Remove this question from the assessment?',
            confirmLabel: 'Remove',
            danger: true,
        });
        if (ok) router.delete(`/studio/questions/${q.id}`, { preserveScroll: true });
    }

    return (
        <div>
            <div className="mb-2 flex items-center gap-2">
                <span className="text-xs font-medium text-zinc-400">Q{index + 1}</span>
                <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {TYPE_LABEL[q.type] ?? q.type}
                </span>
                <span className="text-xs text-zinc-500 dark:text-zinc-400">
                    {q.points} {q.points === 1 ? 'pt' : 'pts'}
                </span>
                {canManage && (
                    <div className="ml-auto flex gap-1 text-xs">
                        <button
                            type="button"
                            onClick={onEdit}
                            className="rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            onClick={() => void remove()}
                            className="rounded px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                        >
                            Delete
                        </button>
                    </div>
                )}
            </div>

            <p className="whitespace-pre-wrap text-sm">{bodyToText(q.stem.body) || <em className="text-zinc-400">No stem</em>}</p>

            <AnswerSummary q={q} />
        </div>
    );
}

function AnswerSummary({ q }: { q: Question }) {
    if (q.type === 'mcq_single' || q.type === 'mcq_multi') {
        return (
            <ul className="mt-2 space-y-1 text-sm">
                {q.options.map((o, i) => (
                    <li key={i} className={o.correct ? 'font-medium text-emerald-700 dark:text-emerald-300' : 'text-zinc-600 dark:text-zinc-400'}>
                        {o.correct ? '✓' : '○'} {o.text}
                    </li>
                ))}
            </ul>
        );
    }
    if (q.type === 'true_false') {
        return <p className="mt-2 text-sm text-emerald-700 dark:text-emerald-300">Answer: {q.grading.answer ? 'True' : 'False'}</p>;
    }
    if (q.type === 'numeric') {
        return (
            <p className="mt-2 text-sm text-emerald-700 dark:text-emerald-300">
                Answer: {String(q.grading.answer)} ± {String(q.grading.tolerance)}
            </p>
        );
    }
    if (q.type === 'short_answer') {
        const accept = Array.isArray(q.grading.accept) ? (q.grading.accept as string[]) : [];
        return (
            <p className="mt-2 text-sm text-emerald-700 dark:text-emerald-300">
                Accepts: {accept.join(', ')}
                {q.grading.fuzzy ? ` (fuzzy ≤ ${String(q.grading.max_distance)})` : ''}
            </p>
        );
    }
    return <p className="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Graded by a person.</p>;
}

/* ------------------------------------------------------------------------- */

function QuestionForm({
    bankId,
    type,
    question,
    onDone,
}: {
    bankId: string;
    type: string;
    question?: Question;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;

    const [stem, setStem] = useState(() => (question ? bodyToText(question.stem.body) : ''));
    const [explanation, setExplanation] = useState(() =>
        question?.explanation ? bodyToText(question.explanation.body) : '',
    );
    const [points, setPoints] = useState(() => question?.points ?? 1);
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    // MCQ
    const initialOptions =
        question && (type === 'mcq_single' || type === 'mcq_multi') && question.options.length > 0
            ? question.options.map((o) => ({ ...o }))
            : [
                  { text: '', correct: type === 'mcq_single' },
                  { text: '', correct: false },
              ];
    const [options, setOptions] = useState<Option[]>(initialOptions);
    const [scoring, setScoring] = useState(() => String(question?.grading.scoring ?? 'all_or_nothing'));

    // True/false, numeric
    const [tfAnswer, setTfAnswer] = useState(() => Boolean(question?.grading.answer ?? true));
    const [numAnswer, setNumAnswer] = useState(() => String(question?.grading.answer ?? ''));
    const [tolerance, setTolerance] = useState(() => String(question?.grading.tolerance ?? '0'));

    // Short answer
    const [accept, setAccept] = useState<string[]>(() =>
        Array.isArray(question?.grading.accept) && question.grading.accept.length > 0
            ? (question.grading.accept as string[])
            : [''],
    );
    const [fuzzy, setFuzzy] = useState(() => Boolean(question?.grading.fuzzy ?? false));
    const [maxDistance, setMaxDistance] = useState(() => String(question?.grading.max_distance ?? 2));

    function payload(): Record<string, unknown> {
        const base: Record<string, unknown> = { stem, explanation, points };
        if (!question) base.type = type;

        switch (type) {
            case 'mcq_single':
            case 'mcq_multi':
                base.options = options.map((o) => ({ text: o.text, correct: o.correct }));
                if (type === 'mcq_multi') base.scoring = scoring;
                break;
            case 'true_false':
                base.answer = tfAnswer;
                break;
            case 'numeric':
                base.answer = numAnswer;
                base.tolerance = tolerance;
                break;
            case 'short_answer':
                base.accept = accept.filter((a) => a.trim() !== '');
                base.fuzzy = fuzzy;
                base.max_distance = Number(maxDistance);
                break;
        }
        return base;
    }

    // Catch the missing answer-key before the server rejects it opaquely — every
    // type has fields the grader cannot do without, and a blank one is the most
    // common reason a question "won't add".
    function validate(): string | null {
        if (stem.trim() === '') return 'Enter the question text.';
        if (!(points > 0)) return 'Points must be greater than zero.';

        if (stem.length > 5000) return 'The question is too long (max 5000 characters).';

        if (type === 'mcq_single' || type === 'mcq_multi') {
            if (options.filter((o) => o.text.trim() !== '').length < 2) return 'Add at least two options.';
            if (!options.some((o) => o.correct)) return 'Mark at least one option correct.';
            if (options.some((o) => o.text.length > 1000)) return 'An option is too long (max 1000 characters).';
        }
        if (type === 'short_answer') {
            if (accept.every((a) => a.trim() === '')) return 'Enter at least one accepted answer.';
            if (accept.some((a) => a.trim().length > 200)) {
                return 'An accepted answer is too long (max 200 characters). For long responses use an Essay question.';
            }
        }
        if (type === 'numeric' && (numAnswer.trim() === '' || Number.isNaN(Number(numAnswer)))) {
            return 'Enter the numeric answer.';
        }
        return null;
    }

    function submit() {
        const problem = validate();
        if (problem) {
            setFormError(problem);
            return;
        }
        setFormError(null);
        setSaving(true);
        const opts = { preserveScroll: true, onFinish: () => setSaving(false), onSuccess: onDone };
        if (question) {
            router.patch(`/studio/questions/${question.id}`, payload() as never, opts);
        } else {
            router.post(`/studio/question-banks/${bankId}/questions`, payload() as never, opts);
        }
    }

    // Selecting a correct option for single-answer clears the others (radio).
    function setSingleCorrect(index: number) {
        setOptions((os) => os.map((o, i) => ({ ...o, correct: i === index })));
    }

    return (
        <div className="w-full space-y-3 rounded-lg border border-indigo-200 bg-indigo-50/40 p-4 dark:border-indigo-900 dark:bg-indigo-950/30">
            <p className="text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">
                {question ? 'Edit' : 'New'} · {TYPE_LABEL[type] ?? type}
            </p>

            <Field label="Question" error={errors.stem}>
                <textarea
                    rows={2}
                    value={stem}
                    onChange={(e) => setStem(e.target.value)}
                    className={inputClass}
                    placeholder="Ask the question…"
                />
            </Field>

            {(type === 'mcq_single' || type === 'mcq_multi') && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">Options</p>
                    {errors.options && <p className="text-sm text-red-600">{errors.options}</p>}
                    {options.map((o, i) => (
                        <div key={i} className="flex items-center gap-2">
                            <input
                                type={type === 'mcq_single' ? 'radio' : 'checkbox'}
                                checked={o.correct}
                                onChange={(e) =>
                                    type === 'mcq_single'
                                        ? setSingleCorrect(i)
                                        : setOptions((os) =>
                                              os.map((x, j) => (j === i ? { ...x, correct: e.target.checked } : x)),
                                          )
                                }
                                aria-label={`Option ${i + 1} correct`}
                            />
                            <input
                                value={o.text}
                                onChange={(e) =>
                                    setOptions((os) => os.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)))
                                }
                                placeholder={`Option ${i + 1}`}
                                className={`${inputClass} flex-1`}
                            />
                            {options.length > 2 && (
                                <button
                                    type="button"
                                    onClick={() => setOptions((os) => os.filter((_, j) => j !== i))}
                                    className="text-sm text-red-600"
                                    aria-label="Remove option"
                                >
                                    ✕
                                </button>
                            )}
                        </div>
                    ))}
                    <button
                        type="button"
                        onClick={() => setOptions((os) => [...os, { text: '', correct: false }])}
                        className="text-sm text-indigo-600 hover:underline"
                    >
                        + Add option
                    </button>
                    {type === 'mcq_multi' && (
                        <Field label="Scoring">
                            <select value={scoring} onChange={(e) => setScoring(e.target.value)} className={inputClass}>
                                <option value="all_or_nothing">All or nothing</option>
                                <option value="partial">Partial credit</option>
                            </select>
                        </Field>
                    )}
                </div>
            )}

            {type === 'true_false' && (
                <Field label="Correct answer">
                    <select value={String(tfAnswer)} onChange={(e) => setTfAnswer(e.target.value === 'true')} className={inputClass}>
                        <option value="true">True</option>
                        <option value="false">False</option>
                    </select>
                </Field>
            )}

            {type === 'numeric' && (
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Answer" error={errors.answer}>
                        <input value={numAnswer} onChange={(e) => setNumAnswer(e.target.value)} className={inputClass} inputMode="decimal" />
                    </Field>
                    <Field label="± Tolerance" error={errors.tolerance}>
                        <input value={tolerance} onChange={(e) => setTolerance(e.target.value)} className={inputClass} inputMode="decimal" />
                    </Field>
                </div>
            )}

            {type === 'short_answer' && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">Accepted answers</p>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                        Enter at least one answer that will be marked correct. Matching ignores case
                        and surrounding spaces.
                    </p>
                    {errors.accept && <p className="text-sm text-red-600">{errors.accept}</p>}
                    {accept.map((a, i) => (
                        <div key={i} className="flex items-center gap-2">
                            <input
                                value={a}
                                onChange={(e) => setAccept((as) => as.map((x, j) => (j === i ? e.target.value : x)))}
                                placeholder="An acceptable answer"
                                className={`${inputClass} flex-1`}
                            />
                            {accept.length > 1 && (
                                <button type="button" onClick={() => setAccept((as) => as.filter((_, j) => j !== i))} className="text-sm text-red-600">
                                    ✕
                                </button>
                            )}
                        </div>
                    ))}
                    <button type="button" onClick={() => setAccept((as) => [...as, ''])} className="text-sm text-indigo-600 hover:underline">
                        + Add accepted answer
                    </button>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={fuzzy} onChange={(e) => setFuzzy(e.target.checked)} />
                        Accept near-miss spellings
                    </label>
                    {fuzzy && (
                        <Field label="Max edit distance">
                            <input value={maxDistance} onChange={(e) => setMaxDistance(e.target.value)} className={inputClass} inputMode="numeric" />
                        </Field>
                    )}
                </div>
            )}

            {type === 'essay' && (
                <p className="text-sm text-zinc-600 dark:text-zinc-400">
                    Essays are always graded by a person — there is no answer key.
                </p>
            )}

            <div className="grid grid-cols-2 gap-3">
                <Field label="Points" error={errors.points}>
                    <input
                        value={points}
                        onChange={(e) => setPoints(Number(e.target.value))}
                        className={inputClass}
                        inputMode="decimal"
                    />
                </Field>
            </div>

            <Field label="Explanation (shown after grading, optional)">
                <textarea rows={2} value={explanation} onChange={(e) => setExplanation(e.target.value)} className={inputClass} />
            </Field>

            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={submit}
                    disabled={saving}
                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    {question ? 'Save' : 'Add question'}
                </button>
                <button type="button" onClick={onDone} className="rounded-md px-3 py-1.5 text-sm">
                    Cancel
                </button>
                {formError && (
                    <p role="alert" className="self-center text-sm text-red-600">
                        {formError}
                    </p>
                )}
            </div>

            {/* Per-item server errors (accept.0, options.1.text, …) that no single
                field above renders — surfaced here so nothing fails silently. */}
            {Object.entries(errors)
                .filter(([key]) => key.includes('.'))
                .map(([key, message]) => (
                    <p key={key} role="alert" className="text-sm text-red-600">
                        {message}
                    </p>
                ))}
        </div>
    );
}

const inputClass =
    'w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900';

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1">
            <label className="block text-sm font-medium">{label}</label>
            {children}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}
