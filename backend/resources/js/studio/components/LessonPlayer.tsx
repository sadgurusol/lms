import { useEffect, useRef, useState } from 'react';
import { BlockView, type Block } from './BlockView';
import AnimatedRevealPreview, { type Fragment, type RevealHandle } from './AnimatedRevealPreview';

type Step = { id: string; title: string; blocks: Block[] };

/** A learner-style step player over a lesson's Step nodes: one step at a time
 *  with Previous / Next and Play/Pause (in the footer). A simulation/animation is
 *  shown full-view; its instructions sit behind an "Instructions" toggle. The
 *  stage is fixed — beats appear within it; the background never grows. */
export default function LessonPlayer({
    lessonNodeId,
    steps: stepsProp,
    title,
    onClose,
}: {
    lessonNodeId?: string;
    steps?: Step[];
    title: string;
    onClose: () => void;
}) {
    const [steps, setSteps] = useState<Step[] | null>(stepsProp ?? null);
    const [index, setIndex] = useState(0);
    const [showInstructions, setShowInstructions] = useState(false);
    const [error, setError] = useState('');
    const [revealPlaying, setRevealPlaying] = useState(false);
    const revealRef = useRef<RevealHandle>(null);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    useEffect(() => {
        if (stepsProp) {
            setSteps(stepsProp);
            return;
        }
        if (!lessonNodeId) return;
        fetch(`/studio/course-nodes/${lessonNodeId}/lesson-preview`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((d) => setSteps((d.steps as Step[]) ?? []))
            .catch(() => setError('Could not load the lesson.'));
    }, [lessonNodeId, stepsProp]);

    useEffect(() => {
        setShowInstructions(false);
        setRevealPlaying(false);
    }, [index]);

    // Play a non-reveal step's narration clip on show (reveals narrate per beat).
    useEffect(() => {
        const stop = () => {
            if (audioRef.current) {
                audioRef.current.pause();
                audioRef.current.src = '';
                audioRef.current = null;
            }
        };
        stop();
        const s = steps?.[index];
        if (!s) return stop;
        const isReveal = s.blocks.some((b) => b.type === 'animated_reveal');
        const url = isReveal
            ? undefined
            : (s.blocks.find((b) => b.type === 'audio')?.payload.url as string | undefined);
        if (url) {
            const a = new Audio(url);
            audioRef.current = a;
            a.play().catch(() => {});
        }
        return stop;
    }, [steps, index]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'ArrowRight') go(1);
            else if (e.key === 'ArrowLeft') go(-1);
            else if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [steps, index]);

    function go(delta: number) {
        setIndex((i) => (!steps ? i : Math.min(Math.max(i + delta, 0), steps.length - 1)));
    }

    const step = steps?.[index];
    const media = step?.blocks.find((b) => b.type === 'simulation' || b.type === 'animation');
    const reveal = step?.blocks.find((b) => b.type === 'animated_reveal');
    const instructionBlocks = step?.blocks.filter((b) => b.type === 'rich_text' || b.type === 'animated_reveal') ?? [];
    const hasInstructions = !!media && instructionBlocks.length > 0;

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-zinc-950 text-white">
            {/* Header */}
            <header className="flex h-14 shrink-0 items-center justify-between border-b border-white/10 px-5">
                <div className="flex items-center gap-2 truncate">
                    <span className="text-indigo-400">▶</span>
                    <span className="truncate font-semibold">{title}</span>
                    {step && <span className="truncate text-sm text-zinc-400">· {step.title}</span>}
                </div>
                <div className="flex items-center gap-3 text-sm text-zinc-400">
                    {steps && <span>{steps.length ? index + 1 : 0} / {steps?.length ?? 0}</span>}
                    <button onClick={onClose} className="rounded p-1.5 hover:bg-white/10">✕</button>
                </div>
            </header>

            {/* Progress */}
            <div className="h-1 shrink-0 bg-white/10">
                <div
                    className="h-1 bg-indigo-500 transition-all"
                    style={{ width: steps && steps.length ? `${((index + 1) / steps.length) * 100}%` : '0%' }}
                />
            </div>

            {/* Stage — fixed; content lives inside, the background never grows */}
            <div className="relative min-h-0 flex-1 overflow-hidden">
                {error && <div className="flex h-full items-center justify-center text-zinc-400">{error}</div>}
                {!steps && !error && <div className="flex h-full items-center justify-center text-zinc-400">Loading…</div>}
                {steps && steps.length === 0 && (
                    <div className="flex h-full items-center justify-center text-zinc-400">This lesson has no steps yet.</div>
                )}

                {step && media ? (
                    <div className="absolute inset-0 bg-black">
                        {media.type === 'simulation' ? (
                            <iframe
                                src={String(media.payload.url ?? '')}
                                sandbox="allow-scripts"
                                title="Interactive simulation"
                                className="h-full w-full border-0 bg-white"
                            />
                        ) : (
                            <video
                                src={String(media.payload.url ?? '')}
                                poster={media.payload.poster_url as string | undefined}
                                controls
                                className="h-full w-full bg-black object-contain"
                            />
                        )}
                        {hasInstructions && showInstructions && (
                            // A bottom sheet sized to its content, so the simulation stays visible above it.
                            <div className="absolute inset-x-0 bottom-0 z-[5] max-h-[60%] overflow-y-auto rounded-t-2xl border-t border-white/10 bg-zinc-900/95 px-6 pb-6 pt-2 shadow-2xl backdrop-blur">
                                <div className="mx-auto max-w-3xl">
                                    <div className="sticky top-0 -mx-6 mb-1 flex justify-end bg-zinc-900/95 px-6 py-1">
                                        <button
                                            onClick={() => setShowInstructions(false)}
                                            className="rounded p-1 text-zinc-400 hover:bg-white/10 hover:text-white"
                                            aria-label="Close instructions"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    {instructionBlocks.map((b, i) => (
                                        <div key={i} className="mb-3">
                                            <BlockView block={b} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                ) : step && reveal ? (
                    <div className="absolute inset-0 flex justify-center overflow-y-auto p-8 md:p-12">
                        <div className="w-full max-w-3xl">
                            <AnimatedRevealPreview
                                ref={revealRef}
                                bare
                                autoplay
                                key={index}
                                fragments={(reveal.payload.fragments as Fragment[] | undefined) ?? []}
                                onPlayingChange={setRevealPlaying}
                            />
                        </div>
                    </div>
                ) : step ? (
                    <div className="absolute inset-0 overflow-y-auto p-8 md:p-12">
                        <div className="mx-auto max-w-3xl space-y-4">
                            {step.blocks.map((b, i) => (
                                <BlockView key={i} block={b} />
                            ))}
                        </div>
                    </div>
                ) : null}
            </div>

            {/* Controls — Previous · Play/Pause · dots · Next */}
            <footer className="flex shrink-0 items-center gap-3 border-t border-white/10 px-6 py-3">
                <button
                    onClick={() => go(-1)}
                    disabled={index === 0}
                    className="flex items-center gap-1 rounded-md px-4 py-2 text-sm text-zinc-300 hover:bg-white/10 disabled:opacity-30"
                >
                    ← Previous
                </button>

                {reveal && (
                    <button
                        onClick={() => revealRef.current?.toggle()}
                        className="rounded-full bg-indigo-600 px-4 py-2 text-sm font-medium hover:bg-indigo-500"
                    >
                        {revealPlaying ? '⏸ Pause' : '▶ Play'}
                    </button>
                )}

                {hasInstructions && (
                    <button
                        onClick={() => setShowInstructions((s) => !s)}
                        className={`rounded-md px-4 py-2 text-sm ${
                            showInstructions ? 'bg-indigo-600 text-white' : 'bg-white/10 hover:bg-white/20'
                        }`}
                    >
                        ⓘ Instructions
                    </button>
                )}

                <div className="flex flex-1 justify-center gap-1.5">
                    {(steps ?? []).map((_, i) => (
                        <button
                            key={i}
                            onClick={() => setIndex(i)}
                            className={`h-2 w-2 rounded-full ${i === index ? 'bg-indigo-500' : 'bg-white/25 hover:bg-white/40'}`}
                        />
                    ))}
                </div>

                <button
                    onClick={() => go(1)}
                    disabled={!steps || index >= steps.length - 1}
                    className="flex items-center gap-1 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium hover:bg-indigo-500 disabled:opacity-30"
                >
                    Next →
                </button>
            </footer>
        </div>
    );
}
