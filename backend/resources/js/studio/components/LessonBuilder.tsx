import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AnimatedRevealPreview, { type Fragment } from './AnimatedRevealPreview';
import { type Block } from './BlockView';

/** Flatten a step's committed LMS blocks back to the native step the builder and
 *  commit understand — so opening the builder edits the existing steps rather
 *  than starting from scratch. Animated reveals + simulation/animation URLs are
 *  preserved exactly; a plain rich_text falls back to its text. */
function portableToText(body: unknown): string {
    if (!Array.isArray(body)) return '';
    return (body as Array<Record<string, unknown>>)
        .map((b) => {
            const text = ((b.children as Array<{ text?: string }> | undefined) ?? []).map((c) => c.text ?? '').join('');
            if (b.style === 'h2') return `## ${text}`;
            if (b.style === 'h3') return `### ${text}`;
            if (b.listItem) return `- ${text}`;
            return text;
        })
        .filter(Boolean)
        .join('\n\n');
}

function reconstructNative(s: { title: string; blocks: Block[] }): Step {
    const reveal = s.blocks.find((b) => b.type === 'animated_reveal');
    const sim = s.blocks.find((b) => b.type === 'simulation');
    const anim = s.blocks.find((b) => b.type === 'animation');
    const rich = s.blocks.find((b) => b.type === 'rich_text');
    const blocks: Array<Record<string, unknown>> = [];
    if (sim) blocks.push({ type: 'simulation', embed_url: sim.payload.url });
    if (anim) blocks.push({ type: 'animation', url: anim.payload.url });
    if (!reveal && rich) blocks.push({ type: 'text', markdown: portableToText(rich.payload.body) });
    const voice = (reveal?.payload.voice_script as string) ?? '';
    return {
        step_type: 'concept',
        title: s.title,
        voice_script: voice,
        blocks,
        animation: reveal ? { fragments: (reveal.payload.fragments as Fragment[]) ?? [], voice_script: voice } : null,
    };
}

type Step = {
    step_number?: number;
    step_type?: string;
    title?: string;
    voice_script?: string;
    blocks?: Array<Record<string, unknown>>;
    animation?: { fragments?: Fragment[]; voice_script?: string } | null;
};

function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m?.[1] ? decodeURIComponent(m[1]) : '';
}

async function api(url: string, method: string, body?: unknown): Promise<Record<string, unknown>> {
    const res = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'same-origin',
    });
    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
    if (!res.ok) throw new Error((data.message as string) ?? 'Request failed');
    return data;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** Text extracted from a step's native blocks, for a quick non-animated preview. */
function stepText(step: Step): string {
    for (const b of step.blocks ?? []) {
        if ((b as { type?: string }).type === 'text' && (b as { markdown?: string }).markdown) {
            return (b as { markdown: string }).markdown;
        }
    }
    return '';
}

function mediaChips(step: Step): string[] {
    const chips = new Set<string>();
    for (const b of step.blocks ?? []) {
        const t = (b as { type?: string }).type;
        if (t && ['simulation', 'animation', 'image', 'formula'].includes(t)) chips.add(t);
    }
    return [...chips];
}

export default function LessonBuilder({
    lessonNodeId,
    lessonTitle,
    courseId,
    onClose,
}: {
    lessonNodeId: string;
    lessonTitle: string;
    courseId: string;
    onClose: () => void;
}) {
    const [instructions, setInstructions] = useState('');
    const [targetSteps, setTargetSteps] = useState<number | null>(null);
    const [animated, setAnimated] = useState(true);
    const [accepted, setAccepted] = useState<Step[]>([]);
    const [draft, setDraft] = useState<Step | null>(null);
    const [editingIndex, setEditingIndex] = useState<number | null>(null);
    const [isLast, setIsLast] = useState(false);
    const [feedback, setFeedback] = useState('');
    const [busy, setBusy] = useState(false);
    const [busyLabel, setBusyLabel] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [previewKey, setPreviewKey] = useState(0);

    const base = `/studio/course-nodes/${lessonNodeId}/lesson-builder`;

    useEffect(() => {
        void init();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Open in edit mode: load the lesson's existing steps; only auto-draft when
    // there are none.
    async function init() {
        try {
            const res = await fetch(`${base.replace('/lesson-builder', '')}/lesson-preview`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const d = (await res.json()) as { steps?: Array<{ title: string; blocks: Block[] }> };
            const existing = (d.steps ?? []).map(reconstructNative);
            if (existing.length) setAccepted(existing);
            else void draftNext();
        } catch {
            void draftNext();
        }
    }

    async function runStep(url: string, body: unknown, label: string): Promise<{ step: Step; is_last: boolean }> {
        setBusy(true);
        setBusyLabel(label);
        setError('');
        try {
            const data = await api(url, 'POST', body);
            const jobId = data.job_id as string | undefined;
            if (!jobId) return { step: data.step as Step, is_last: !!data.is_last };
            for (let i = 0; i < 150; i++) {
                await sleep(2000);
                const s = (await api(`${base}/step-status?job_id=${encodeURIComponent(jobId)}`, 'GET')) as Record<string, unknown>;
                if (s.status === 'completed') return { step: s.step as Step, is_last: !!s.is_last };
                if (s.status === 'failed') throw new Error((s.error as string) ?? 'Generation failed');
            }
            throw new Error('Timed out waiting for the step');
        } finally {
            setBusy(false);
        }
    }

    const contextFor = (index: number | null): Step[] => (index === null ? accepted : accepted.slice(0, index));

    async function draftNext() {
        setEditingIndex(null);
        const stepNumber = accepted.length + 1;
        try {
            const { step, is_last } = await runStep(
                `${base}/next-step`,
                { steps: accepted, instructions, step_number: stepNumber, target_steps: targetSteps, animated },
                `Drafting step ${stepNumber}…`,
            );
            setDraft(step);
            setIsLast(is_last);
            setFeedback('');
        } catch (e) {
            setError((e as Error).message);
        }
    }

    async function regenerate() {
        const idx = editingIndex;
        const stepNumber = idx === null ? accepted.length + 1 : idx + 1;
        try {
            const { step, is_last } = await runStep(
                `${base}/next-step`,
                { steps: contextFor(idx), instructions, step_number: stepNumber, target_steps: targetSteps, animated },
                `Regenerating step ${stepNumber}…`,
            );
            setDraft(step);
            setIsLast(is_last);
            setFeedback('');
        } catch (e) {
            setError((e as Error).message);
        }
    }

    async function revise() {
        if (!feedback.trim() || !draft) return;
        try {
            const { step } = await runStep(
                `${base}/revise-step`,
                { step: draft, feedback, steps: contextFor(editingIndex), animated: animated || !!draft.animation },
                'Revising step…',
            );
            setDraft({ ...step, step_number: draft.step_number });
            setFeedback('');
        } catch (e) {
            setError((e as Error).message);
        }
    }

    function accept() {
        if (!draft) return;
        const idx = editingIndex;
        const wasNew = idx === null;
        const wasLast = isLast;
        const next = [...accepted];
        if (idx === null) next.push(draft);
        else next[idx] = draft;
        setAccepted(next);
        setDraft(null);
        setEditingIndex(null);
        setIsLast(false);
        const reachedTarget = targetSteps != null && next.length >= targetSteps;
        if (wasNew && !wasLast && !reachedTarget) setTimeout(() => void draftNext(), 0);
    }

    function editAccepted(i: number) {
        if (busy) return;
        const s = accepted[i];
        if (!s) return;
        setEditingIndex(i);
        setDraft({ ...s });
        setAnimated(!!s.animation);
        setIsLast(false);
        setFeedback('');
        setPreviewKey((k) => k + 1);
    }

    async function save() {
        setSaving(true);
        setError('');
        try {
            await api(`${base}/commit`, 'POST', { steps: accepted.map((s, i) => ({ ...s, step_number: i + 1 })) });
            onClose();
            router.visit(`/studio/courses/${courseId}`);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setSaving(false);
        }
    }

    const draftFrags = draft?.animation?.fragments ?? [];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div className="flex h-[88vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-950">
                {/* Header */}
                <div className="flex shrink-0 items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                    <div>
                        <h3 className="font-semibold text-zinc-900 dark:text-white">✨ Animated lesson builder</h3>
                        <p className="text-xs text-zinc-500">{lessonTitle} · step-by-step with AI</p>
                    </div>
                    <button onClick={onClose} className="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">
                        ✕
                    </button>
                </div>

                <div className="flex min-h-0 flex-1">
                    {/* Accepted steps + options */}
                    <div className="flex w-64 shrink-0 flex-col border-r border-zinc-200 dark:border-zinc-800">
                        <div className="border-b border-zinc-200 px-3 py-2 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                            Lesson so far · {accepted.length}
                        </div>
                        <div className="flex-1 space-y-1 overflow-y-auto p-2">
                            {accepted.map((s, i) => (
                                <button
                                    key={i}
                                    onClick={() => editAccepted(i)}
                                    className={`w-full rounded-md border px-3 py-2 text-left ${
                                        editingIndex === i
                                            ? 'border-zinc-400 bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800'
                                            : 'border-transparent hover:bg-zinc-50 dark:hover:bg-zinc-900'
                                    }`}
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-xs text-zinc-400">{i + 1}</span>
                                        <span className="rounded bg-zinc-200 px-1.5 text-[10px] capitalize dark:bg-zinc-700">
                                            {s.step_type}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 truncate text-sm text-zinc-800 dark:text-zinc-100">{s.title || 'Untitled'}</p>
                                </button>
                            ))}
                            {accepted.length === 0 && (
                                <p className="px-2 py-6 text-center text-xs text-zinc-500">The first step is being drafted…</p>
                            )}
                        </div>
                        <div className="space-y-3 border-t border-zinc-200 p-3 dark:border-zinc-800">
                            <label className="block text-[11px] text-zinc-500">
                                Target length
                                <select
                                    value={targetSteps ?? ''}
                                    onChange={(e) => setTargetSteps(e.target.value ? Number(e.target.value) : null)}
                                    className="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                                >
                                    <option value="">Let AI decide</option>
                                    {[4, 5, 6, 7, 8, 9, 10].map((n) => (
                                        <option key={n} value={n}>
                                            {n} steps
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex items-start gap-2 text-[11px] text-zinc-600 dark:text-zinc-300">
                                <input type="checkbox" checked={animated} onChange={(e) => setAnimated(e.target.checked)} className="mt-0.5" />
                                <span>
                                    <span className="font-medium">Animated reveal</span> — beats that fade/slide/type in, narrated.
                                </span>
                            </label>
                        </div>
                    </div>

                    {/* Working area */}
                    <div className="flex min-h-0 flex-1 flex-col">
                        <div className="shrink-0 border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                            <label className="text-[11px] text-zinc-500">Instructions to the AI (applies to every step)</label>
                            <textarea
                                value={instructions}
                                onChange={(e) => setInstructions(e.target.value)}
                                rows={2}
                                className="mt-1 w-full resize-none rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                placeholder="e.g. use real-world examples; include a simulation for the water cycle…"
                            />
                        </div>

                        <div className="flex-1 overflow-y-auto p-5">
                            {busy ? (
                                <div className="flex h-full flex-col items-center justify-center text-center">
                                    <div className="mb-4 h-10 w-10 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" />
                                    <p className="text-sm text-zinc-600 dark:text-zinc-300">{busyLabel}</p>
                                    <p className="mt-1 text-xs text-zinc-500">AI-generated media can take a little longer.</p>
                                </div>
                            ) : draft ? (
                                <div className="mx-auto max-w-3xl">
                                    <div className="mb-3 flex items-center gap-2">
                                        <span className="rounded bg-zinc-200 px-2 py-0.5 text-[10px] capitalize dark:bg-zinc-700">
                                            {editingIndex === null ? `New step ${accepted.length + 1}` : `Editing step ${editingIndex + 1}`}
                                        </span>
                                        {isLast && (
                                            <span className="rounded bg-green-100 px-2 py-0.5 text-[10px] text-green-700 dark:bg-green-900/40 dark:text-green-300">
                                                AI marked this the final step
                                            </span>
                                        )}
                                        {mediaChips(draft).map((c) => (
                                            <span key={c} className="rounded bg-indigo-100 px-2 py-0.5 text-[10px] text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                                {c}
                                            </span>
                                        ))}
                                    </div>

                                    <label className="text-[11px] text-zinc-500">Title</label>
                                    <input
                                        value={draft.title ?? ''}
                                        onChange={(e) => setDraft({ ...draft, title: e.target.value })}
                                        className="mb-4 mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                    />

                                    {draftFrags.length > 0 ? (
                                        <AnimatedRevealPreview key={`${previewKey}-${draftFrags.length}`} fragments={draftFrags} />
                                    ) : (
                                        <div className="whitespace-pre-wrap rounded-lg border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                                            {stepText(draft) || 'No preview text.'}
                                        </div>
                                    )}

                                    <div className="mt-4 rounded-lg bg-zinc-100 p-3 dark:bg-zinc-800/50">
                                        <label className="text-[11px] text-zinc-500">Ask AI to change this step</label>
                                        <div className="mt-1 flex gap-2">
                                            <input
                                                value={feedback}
                                                onChange={(e) => setFeedback(e.target.value)}
                                                onKeyUp={(e) => e.key === 'Enter' && void revise()}
                                                className="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                                placeholder="e.g. simpler; add a cricket example; include a simulation…"
                                            />
                                            <button
                                                onClick={() => void revise()}
                                                disabled={!feedback.trim() || busy}
                                                className="rounded-md border border-zinc-300 px-3 text-sm disabled:opacity-40 dark:border-zinc-700"
                                            >
                                                Revise
                                            </button>
                                        </div>
                                    </div>
                                    {error && <p className="mt-2 text-sm text-red-500">{error}</p>}
                                </div>
                            ) : (
                                <div className="flex h-full flex-col items-center justify-center text-center">
                                    <h4 className="font-medium text-zinc-800 dark:text-zinc-100">{accepted.length ? 'Lesson ready' : 'Ready to build'}</h4>
                                    <p className="mt-1 max-w-sm text-sm text-zinc-500">
                                        {accepted.length ? 'Add another step, revise any step, or save.' : 'Generate the first step and refine it.'}
                                    </p>
                                    <button onClick={() => void draftNext()} className="mt-5 rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                                        {accepted.length ? '+ Generate next step' : 'Generate first step'}
                                    </button>
                                    {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
                                </div>
                            )}
                        </div>

                        {/* Footer */}
                        <div className="flex shrink-0 items-center justify-between gap-3 border-t border-zinc-200 px-5 py-3 dark:border-zinc-800">
                            <button onClick={onClose} className="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                Cancel
                            </button>
                            <div className="flex items-center gap-2">
                                {draft && !busy && (
                                    <>
                                        <button onClick={() => setDraft(null)} className="px-3 py-2 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                            Discard
                                        </button>
                                        <button onClick={() => void regenerate()} className="rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">
                                            Regenerate
                                        </button>
                                        <button onClick={accept} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                                            {editingIndex === null ? (isLast ? 'Accept & finish' : 'Accept & next') : 'Save changes'}
                                        </button>
                                    </>
                                )}
                                {accepted.length > 0 && !busy && (
                                    <button
                                        onClick={() => void save()}
                                        disabled={saving}
                                        className="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                                    >
                                        {saving ? 'Saving…' : `Save lesson (${accepted.length})`}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
