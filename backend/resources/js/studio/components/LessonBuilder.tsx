import { useEffect, useRef, useState } from 'react';
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
    const audio = s.blocks.find((b) => b.type === 'audio');
    const blocks: Array<Record<string, unknown>> = [];
    if (sim) blocks.push({ type: 'simulation', embed_url: sim.payload.url });
    if (anim) blocks.push({ type: 'animation', url: anim.payload.url });
    if (!reveal && rich) blocks.push({ type: 'text', markdown: portableToText(rich.payload.body) });
    // Self-contained SVG diagrams survive a reopen exactly (no media to re-ingest).
    for (const d of s.blocks.filter((b) => b.type === 'diagram')) {
        blocks.push({ type: 'diagram', svg: d.payload.svg, alt: d.payload.alt, caption: d.payload.caption });
    }
    // Narration: from the reveal (per-step voice_script) or, for a non-reveal
    // step, from its audio block (transcript + pre-generated clip).
    const voice = (reveal?.payload.voice_script as string) ?? (audio?.payload.transcript as string) ?? '';
    return {
        step_type: 'concept',
        title: s.title,
        voice_script: voice,
        audio_url: reveal ? undefined : (audio?.payload.url as string | undefined),
        blocks,
        animation: reveal ? { fragments: (reveal.payload.fragments as Fragment[]) ?? [], voice_script: voice } : null,
    };
}

type Step = {
    step_number?: number;
    step_type?: string;
    title?: string;
    voice_script?: string;
    // Step-level narration clip (non-reveal steps; reveals narrate per fragment).
    audio_url?: string;
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
        if (t && ['simulation', 'animation', 'image', 'diagram', 'formula'].includes(t)) chips.add(t);
    }
    return [...chips];
}

/** The SVG markup of any diagram blocks in a native step, for inline preview. */
function stepDiagrams(step: Step): string[] {
    const out: string[] = [];
    for (const b of step.blocks ?? []) {
        const o = b as { type?: string; svg?: string };
        if (o.type === 'diagram' && typeof o.svg === 'string' && o.svg.trim()) out.push(o.svg);
    }
    return out;
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
    const [autosaving, setAutosaving] = useState(false);
    const [savedCount, setSavedCount] = useState(0);
    const [error, setError] = useState('');
    const [previewKey, setPreviewKey] = useState(0);
    const [voiceBusy, setVoiceBusy] = useState(false);
    // Title-first flow for a NEW step: suggest a title, let the author confirm/
    // edit it, then that title drives the content. `titleDraft` non-null = the
    // confirm-title panel is open.
    const [titleDraft, setTitleDraft] = useState<string | null>(null);
    const [titleBusy, setTitleBusy] = useState(false);
    // Autosave runs on a serial chain so overlapping commits can't land out of
    // order (each commit is a full replace — the last write must be the newest).
    const saveChain = useRef<Promise<void>>(Promise.resolve());
    const pending = useRef(0);
    // Latest accepted list (for debounced autosave to read the newest value), and
    // the debounce timer for text edits (title / narration).
    const latest = useRef<Step[]>([]);
    const autosaveTimer = useRef<number | null>(null);

    const base = `/studio/course-nodes/${lessonNodeId}/lesson-builder`;

    useEffect(() => {
        void init();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Keep `latest` in sync so a debounced autosave always persists the newest list.
    useEffect(() => {
        latest.current = accepted;
    }, [accepted]);

    // Open in edit mode: load the lesson's existing steps. Never auto-generate —
    // the author enters instructions first, then clicks "Generate first step"
    // (the empty state shows that button).
    async function init() {
        try {
            const res = await fetch(`${base.replace('/lesson-builder', '')}/lesson-preview`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const d = (await res.json()) as { steps?: Array<{ title: string; blocks: Block[] }> };
            const existing = (d.steps ?? []).map(reconstructNative);
            if (existing.length) setAccepted(existing);
        } catch {
            // Leave the builder on its empty state; the author starts generation.
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

    // Place a freshly generated step into the lesson and save it now: append it
    // (index === null) or replace the step at `index`, select it, and persist.
    // A generated step IS a saved step — there is no accept/discard.
    function placeStep(step: Step, index: number | null) {
        const base = latest.current;
        const at = index ?? base.length;
        const next = [...base];
        next[at] = step;
        setAccepted(next);
        setDraft(step);
        setEditingIndex(at);
        setIsLast(false);
        void persist(next);
    }

    // Start a new step: suggest a title first and open the confirm-title panel.
    // The author confirms/edits it, then generateFromTitle() writes the content.
    async function beginNewStep() {
        setEditingIndex(null);
        setDraft(null);
        setError('');
        setTitleBusy(true);
        try {
            const d = await api(`${base}/suggest-title`, 'POST', {
                steps: latest.current,
                step_number: latest.current.length + 1,
                target_steps: targetSteps,
            });
            setTitleDraft(((d.title as string) || '').trim());
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setTitleBusy(false);
        }
    }

    // Generate the new step's content for the confirmed title (which drives it and
    // stays fixed), then save + select it.
    async function generateFromTitle() {
        const title = (titleDraft ?? '').trim();
        if (!title) return;
        const context = latest.current;
        const stepNumber = context.length + 1;
        setTitleDraft(null);
        try {
            const { step } = await runStep(
                `${base}/next-step`,
                { steps: context, instructions, step_number: stepNumber, target_steps: targetSteps, animated, title },
                `Generating “${title}”…`,
            );
            placeStep({ ...step, title }, null);
        } catch (e) {
            setError((e as Error).message);
        }
    }

    // Regenerate the currently-shown step in place — keeping its title (the title
    // is never rewritten by a regenerate; it drives the content).
    async function regenerate() {
        const idx = editingIndex;
        const stepNumber = idx === null ? latest.current.length + 1 : idx + 1;
        const context = idx === null ? latest.current : latest.current.slice(0, idx);
        const title = (draft?.title ?? '').trim();
        try {
            const { step } = await runStep(
                `${base}/next-step`,
                { steps: context, instructions, step_number: stepNumber, target_steps: targetSteps, animated, title: title || undefined },
                `Regenerating step ${stepNumber}…`,
            );
            placeStep({ ...step, title: title || step.title }, idx);
        } catch (e) {
            setError((e as Error).message);
        }
    }

    async function revise() {
        if (!feedback.trim() || !draft) return;
        const idx = editingIndex;
        try {
            const { step } = await runStep(
                `${base}/revise-step`,
                { step: draft, feedback, steps: idx === null ? latest.current : latest.current.slice(0, idx), animated: animated || !!draft.animation },
                'Revising step…',
            );
            placeStep({ ...step, step_number: draft.step_number }, idx);
            setFeedback('');
        } catch (e) {
            setError((e as Error).message);
        }
    }

    // Edit the shown step (title / narration text): update the draft AND mirror it
    // into the saved list, then autosave after a short debounce.
    function patchDraft(updated: Step) {
        setDraft(updated);
        if (editingIndex === null) return;
        const next = [...latest.current];
        next[editingIndex] = updated;
        setAccepted(next);
        if (autosaveTimer.current) window.clearTimeout(autosaveTimer.current);
        autosaveTimer.current = window.setTimeout(() => void persist(latest.current), 700);
    }

    // Update the shown step and save immediately (for deliberate actions like
    // generating narration audio — no debounce).
    function saveDraft(updated: Step) {
        setDraft(updated);
        if (editingIndex === null) return;
        const next = [...latest.current];
        next[editingIndex] = updated;
        setAccepted(next);
        void persist(next);
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

    // Autosave the full lesson (a commit is a full replace). Serialized on
    // saveChain so a slower earlier commit can't overwrite a newer one.
    function persist(steps: Step[]): Promise<void> {
        pending.current += 1;
        setAutosaving(true);
        setError('');
        const payload = { steps: steps.map((s, i) => ({ ...s, step_number: i + 1 })) };
        saveChain.current = saveChain.current
            .then(async () => {
                const res = await api(`${base}/commit`, 'POST', payload);
                const warnings = (res.warnings as string[] | undefined) ?? [];
                if (warnings.length) setError(warnings.join(' '));
                setSavedCount(steps.length);
            })
            .catch((e) => setError((e as Error).message))
            .finally(() => {
                pending.current -= 1;
                if (pending.current === 0) setAutosaving(false);
            });
        return saveChain.current;
    }

    // Close: flush any pending debounced edit, wait for saves to land, then close.
    async function closeAfterSave() {
        if (autosaveTimer.current) {
            window.clearTimeout(autosaveTimer.current);
            void persist(latest.current);
        }
        await saveChain.current;
        onClose();
    }

    const draftFrags = draft?.animation?.fragments ?? [];
    const voicedCount = draftFrags.filter((f) => !!f.audio_url).length;
    const allVoiced = draftFrags.length > 0 && voicedCount === draftFrags.length;

    /** Edit one beat's narration text. Editing invalidates its generated clip. */
    function setFragVoice(i: number, text: string) {
        if (!draft) return;
        const frags = [...(draft.animation?.fragments ?? [])];
        frags[i] = { ...frags[i], voice: text, audio_url: undefined };
        patchDraft({ ...draft, animation: { ...(draft.animation ?? {}), fragments: frags } });
    }

    /** Edit a non-reveal step's narration. Editing invalidates its clip. */
    function setStepVoice(text: string) {
        if (!draft) return;
        patchDraft({ ...draft, voice_script: text, audio_url: undefined });
    }

    /** (Re)generate narration audio only — no step rerun. Handles a reveal (one
     *  clip per beat) or a plain/media step (one clip for the whole step). */
    async function generateVoice() {
        if (!draft) return;
        const frags = draft.animation?.fragments ?? [];
        setVoiceBusy(true);
        setError('');
        try {
            if (frags.length > 0) {
                const voices = frags.map((f) => (f.voice ?? '').trim());
                const d = await api(`${base}/voice`, 'POST', { voices });
                const urls = (d.audio_urls as Array<string | null>) ?? [];
                const next = frags.map((f, i) => ({ ...f, audio_url: urls[i] ?? f.audio_url }));
                saveDraft({ ...draft, animation: { ...(draft.animation ?? {}), fragments: next } });
            } else {
                const text = (draft.voice_script ?? '').trim();
                if (!text) return;
                const d = await api(`${base}/voice`, 'POST', { voices: [text] });
                const urls = (d.audio_urls as Array<string | null>) ?? [];
                saveDraft({ ...draft, audio_url: urls[0] ?? undefined });
            }
            setPreviewKey((k) => k + 1); // replay the preview with the new audio
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setVoiceBusy(false);
        }
    }

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
                            {accepted.map((s, i) => {
                                const regenerating = busy && editingIndex === i;
                                const selected = editingIndex === i;
                                return (
                                    <div
                                        key={i}
                                        className={`w-full rounded-md border px-3 py-2 ${
                                            selected
                                                ? 'border-zinc-400 bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800'
                                                : 'border-transparent hover:bg-zinc-50 dark:hover:bg-zinc-900'
                                        }`}
                                    >
                                        <button onClick={() => editAccepted(i)} className="flex w-full items-center gap-2 text-left">
                                            <span className="font-mono text-xs text-zinc-400">{i + 1}</span>
                                            {regenerating ? (
                                                <span className="flex items-center gap-1.5 text-[10px] text-indigo-600 dark:text-indigo-300">
                                                    <span className="h-3 w-3 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" />
                                                    regenerating…
                                                </span>
                                            ) : (
                                                <span className="rounded bg-zinc-200 px-1.5 text-[10px] capitalize dark:bg-zinc-700">
                                                    {s.step_type}
                                                </span>
                                            )}
                                        </button>
                                        {selected ? (
                                            // Edit the title in place — it drives a regenerate of this step.
                                            <input
                                                value={draft?.title ?? s.title ?? ''}
                                                onChange={(e) => patchDraft({ ...(draft ?? s), title: e.target.value })}
                                                placeholder="Step title…"
                                                className="mt-1 w-full rounded border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                            />
                                        ) : (
                                            <button
                                                onClick={() => editAccepted(i)}
                                                className="mt-0.5 block w-full truncate text-left text-sm text-zinc-800 dark:text-zinc-100"
                                            >
                                                {s.title || 'Untitled'}
                                            </button>
                                        )}
                                    </div>
                                );
                            })}

                            {/* A new step drafted but not yet accepted — clearly "in review", not part of the lesson yet. */}
                            {/* A brand-new step being generated (regenerate of an existing one shows on its own row above). */}
                            {busy && editingIndex === null && (
                                <div className="w-full rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-xs text-zinc-400">{accepted.length + 1}</span>
                                        <span className="flex items-center gap-1.5 text-[10px] text-indigo-600 dark:text-indigo-300">
                                            <span className="h-3 w-3 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" />
                                            generating…
                                        </span>
                                    </div>
                                </div>
                            )}

                            {accepted.length === 0 && !busy && !draft && (
                                <p className="px-2 py-6 text-center text-xs text-zinc-500">No steps yet.</p>
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
                            {titleBusy ? (
                                <div className="flex h-full flex-col items-center justify-center text-center">
                                    <div className="mb-4 h-10 w-10 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" />
                                    <p className="text-sm text-zinc-600 dark:text-zinc-300">Suggesting a title…</p>
                                </div>
                            ) : titleDraft !== null ? (
                                // Confirm/edit the step title first — it drives the content.
                                <div className="mx-auto flex h-full max-w-xl flex-col items-center justify-center text-center">
                                    <span className="mb-2 rounded bg-zinc-200 px-2 py-0.5 text-[10px] capitalize dark:bg-zinc-700">
                                        {`Step ${accepted.length + 1}`}
                                    </span>
                                    <h4 className="font-medium text-zinc-800 dark:text-zinc-100">Confirm the step title</h4>
                                    <p className="mt-1 text-xs text-zinc-500">The title drives what the AI writes for this step. Edit it, then generate.</p>
                                    <input
                                        value={titleDraft}
                                        onChange={(e) => setTitleDraft(e.target.value)}
                                        onKeyUp={(e) => e.key === 'Enter' && void generateFromTitle()}
                                        autoFocus
                                        placeholder="Step title…"
                                        className="mt-4 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-center text-base dark:border-zinc-700 dark:bg-zinc-900"
                                    />
                                    <div className="mt-4 flex items-center gap-2">
                                        <button
                                            onClick={() => setTitleDraft(null)}
                                            className="rounded-md px-4 py-2 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            onClick={() => void generateFromTitle()}
                                            disabled={!titleDraft.trim()}
                                            className="rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-40"
                                        >
                                            Generate content →
                                        </button>
                                    </div>
                                    {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
                                </div>
                            ) : busy ? (
                                <div className="flex h-full flex-col items-center justify-center text-center">
                                    <div className="mb-4 h-10 w-10 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" />
                                    <p className="text-sm text-zinc-600 dark:text-zinc-300">{busyLabel}</p>
                                    <p className="mt-1 text-xs text-zinc-500">AI-generated media can take a little longer.</p>
                                </div>
                            ) : draft ? (
                                <div className="mx-auto max-w-3xl">
                                    <div className="mb-3 flex items-center gap-2">
                                        <span className="rounded bg-zinc-200 px-2 py-0.5 text-[10px] capitalize dark:bg-zinc-700">
                                            {`Step ${(editingIndex ?? accepted.length) + 1}`}
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

                                    {/* Title shown read-only here; edit it in the sidebar (it drives regenerate). */}
                                    <h4 className="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">{draft.title || 'Untitled'}</h4>

                                    {draftFrags.length > 0 ? (
                                        <AnimatedRevealPreview key={`${previewKey}-${draftFrags.length}`} fragments={draftFrags} />
                                    ) : (
                                        <div className="whitespace-pre-wrap rounded-lg border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                                            {stepText(draft) || 'No preview text.'}
                                        </div>
                                    )}

                                    {/* Inline SVG diagrams (script-inert markup from the model). */}
                                    {stepDiagrams(draft).map((svg, i) => (
                                        <div
                                            key={i}
                                            className="mt-3 flex justify-center rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900 [&_svg]:h-auto [&_svg]:max-w-full"
                                            dangerouslySetInnerHTML={{ __html: svg }}
                                        />
                                    ))}

                                    {/* Narration: review/edit the voice text, then generate audio for
                                        the beats only — no need to regenerate the whole step. */}
                                    {draftFrags.length > 0 && (
                                        <div className="mt-4 rounded-lg border border-zinc-200 dark:border-zinc-800">
                                            <div className="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs font-medium text-zinc-700 dark:text-zinc-200">Narration</span>
                                                    <span className="text-[10px] text-zinc-500">{voicedCount}/{draftFrags.length} voiced</span>
                                                </div>
                                                <button
                                                    onClick={() => void generateVoice()}
                                                    disabled={voiceBusy}
                                                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                >
                                                    {voiceBusy ? 'Generating voice…' : allVoiced ? '🔊 Regenerate voice' : '🔊 Generate voice'}
                                                </button>
                                            </div>
                                            <div className="max-h-56 space-y-2 overflow-y-auto p-3">
                                                {draftFrags.map((f, i) => (
                                                    <div key={i} className="flex items-start gap-2">
                                                        <span className="mt-2 w-4 shrink-0 text-right font-mono text-[10px] text-zinc-400">{i + 1}</span>
                                                        <textarea
                                                            value={f.voice ?? ''}
                                                            onChange={(e) => setFragVoice(i, e.target.value)}
                                                            rows={2}
                                                            placeholder="Narration for this beat…"
                                                            className="flex-1 resize-none rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                                                        />
                                                        <span
                                                            title={f.audio_url ? 'Voice ready' : 'No audio yet — plays with device voice until generated'}
                                                            className={`mt-2 shrink-0 text-xs ${f.audio_url ? 'text-green-600 dark:text-green-400' : 'text-zinc-300 dark:text-zinc-600'}`}
                                                        >
                                                            ●
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Non-reveal step: one narration for the whole step. */}
                                    {draft && draftFrags.length === 0 && (
                                        <div className="mt-4 rounded-lg border border-zinc-200 dark:border-zinc-800">
                                            <div className="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs font-medium text-zinc-700 dark:text-zinc-200">Narration</span>
                                                    <span className="text-[10px] text-zinc-500">{draft.audio_url ? 'voice ready' : 'no audio yet'}</span>
                                                </div>
                                                <button
                                                    onClick={() => void generateVoice()}
                                                    disabled={voiceBusy || !(draft.voice_script ?? '').trim()}
                                                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                >
                                                    {voiceBusy ? 'Generating voice…' : draft.audio_url ? '🔊 Regenerate voice' : '🔊 Generate voice'}
                                                </button>
                                            </div>
                                            <div className="p-3">
                                                <textarea
                                                    value={draft.voice_script ?? ''}
                                                    onChange={(e) => setStepVoice(e.target.value)}
                                                    rows={3}
                                                    placeholder="Narration for this step…"
                                                    className="w-full resize-none rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                                                />
                                                {draft.audio_url && <audio src={draft.audio_url} controls className="mt-2 w-full" />}
                                            </div>
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
                                        {accepted.length
                                            ? 'Pick a step to review, or generate the next one. Every step is saved automatically.'
                                            : 'Enter any instructions, then generate the first step. Steps save automatically.'}
                                    </p>
                                    <button onClick={() => void beginNewStep()} className="mt-5 rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                                        {accepted.length ? '+ Generate next step' : 'Generate first step'}
                                    </button>
                                    {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
                                </div>
                            )}
                        </div>

                        {/* Footer */}
                        <div className="flex shrink-0 items-center justify-between gap-3 border-t border-zinc-200 px-5 py-3 dark:border-zinc-800">
                            <div className="flex items-center gap-3">
                                <button onClick={() => void closeAfterSave()} className="rounded-md bg-zinc-800 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-zinc-200 dark:text-zinc-900 dark:hover:bg-zinc-300">
                                    Close
                                </button>
                                {/* Autosave status — every generated step is saved automatically. */}
                                {accepted.length > 0 && (
                                    <span className="text-xs text-zinc-500">
                                        {autosaving ? 'Saving…' : savedCount > 0 ? `✓ Saved · ${savedCount} step${savedCount === 1 ? '' : 's'}` : ''}
                                    </span>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                {draft && !busy && titleDraft === null && !titleBusy && (
                                    <button onClick={() => void regenerate()} className="rounded-md border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">
                                        ↻ Regenerate
                                    </button>
                                )}
                                {accepted.length > 0 && !busy && titleDraft === null && !titleBusy && (
                                    <button onClick={() => void beginNewStep()} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                                        + Next step
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
