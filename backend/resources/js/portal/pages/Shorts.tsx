import { useEffect, useRef, useState } from 'react';
import AnimatedRevealPreview, { svgDataUri, type RevealHandle } from '@/studio/components/AnimatedRevealPreview';
import { getShorts, recordShortView, type Short } from '../api';
import { ShareButton } from '../components/ShareButton';
import { subjectTheme } from '../lib/subject';
import { useAsync } from '../lib/useAsync';
import { usePageTitle } from '../lib/usePageTitle';
import { Link } from '../router';

export default function Shorts() {
    usePageTitle('Shorts');
    const focus = new URLSearchParams(window.location.search).get('s') ?? undefined;
    const { loading, data } = useAsync(() => getShorts(focus), []);
    const shorts = data ?? [];
    const [active, setActive] = useState(0);
    const [soundOn, setSoundOn] = useState(false);
    const viewed = useRef<Set<string>>(new Set());
    const containerRef = useRef<HTMLDivElement>(null);

    // Active short via IntersectionObserver — reliable on touch scrolling where
    // scroll math (mobile URL-bar changes) is not.
    useEffect(() => {
        const c = containerRef.current;
        if (!c || !shorts.length) return;
        const sections = Array.from(c.children).filter((el) => el.tagName === 'SECTION') as HTMLElement[];
        const io = new IntersectionObserver(
            (entries) => {
                for (const e of entries) {
                    if (e.isIntersecting && e.intersectionRatio >= 0.55) {
                        const i = sections.indexOf(e.target as HTMLElement);
                        if (i >= 0) setActive(i);
                    }
                }
            },
            { root: c, threshold: [0.55] },
        );
        sections.forEach((s) => io.observe(s));
        return () => io.disconnect();
    }, [shorts.length]);

    // Count a view ~1.5s after a short becomes active (once per short).
    useEffect(() => {
        const s = shorts[active];
        if (!s || viewed.current.has(s.node_id)) return;
        const id = window.setTimeout(() => {
            viewed.current.add(s.node_id);
            recordShortView(s.course_slug, s.node_id);
        }, 1500);
        return () => window.clearTimeout(id);
    }, [active, shorts]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key === 'ArrowDown') scrollTo(active + 1);
            else if (e.key === 'ArrowUp') scrollTo(active - 1);
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active, shorts.length]);

    function scrollTo(i: number) {
        const n = Math.min(Math.max(i, 0), shorts.length - 1);
        (containerRef.current?.children[n] as HTMLElement | undefined)?.scrollIntoView({ behavior: 'smooth' });
    }

    if (loading) {
        return <div className="flex h-screen items-center justify-center bg-black text-white/60">Loading shorts…</div>;
    }
    if (!shorts.length) {
        return (
            <div className="flex h-screen flex-col items-center justify-center gap-3 bg-black px-6 text-center text-white/70">
                <p className="font-display text-2xl text-white">No shorts yet</p>
                <p>Animated lessons appear here as bite-size shorts.</p>
                <Link href="/courses" className="mt-3 rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black">Browse courses</Link>
            </div>
        );
    }

    return (
        <div className="fixed inset-0 z-50 bg-black">
            <Link href="/" className="absolute right-4 top-4 z-30 grid h-9 w-9 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20" aria-label="Close">
                ✕
            </Link>

            <div ref={containerRef} className="h-full snap-y snap-mandatory overflow-y-auto overscroll-contain">
                {shorts.map((s, i) => (
                    <section key={s.node_id} className="flex h-full snap-start snap-always items-center justify-center">
                        <ShortCard short={s} active={i === active} soundOn={soundOn} onEnableSound={() => setSoundOn(true)} />
                    </section>
                ))}
            </div>

            {/* Desktop up/down */}
            <div className="pointer-events-none absolute inset-y-0 right-4 hidden flex-col items-center justify-center gap-3 md:flex">
                <NavBtn dir="↑" onClick={() => scrollTo(active - 1)} disabled={active === 0} />
                <NavBtn dir="↓" onClick={() => scrollTo(active + 1)} disabled={active >= shorts.length - 1} />
            </div>
        </div>
    );
}

function ShortCard({
    short,
    active,
    soundOn,
    onEnableSound,
}: {
    short: Short;
    active: boolean;
    soundOn: boolean;
    onEnableSound: () => void;
}) {
    const t = subjectTheme(short.subject);
    const ref = useRef<RevealHandle>(null);
    const [playing, setPlaying] = useState(true);

    // First tap enables sound and replays this short WITH audio (synchronously, so
    // it counts as the user gesture browsers require). After that, tap = play/pause.
    function onTap() {
        if (!soundOn) {
            onEnableSound();
            ref.current?.restart();
        } else {
            ref.current?.toggle();
        }
    }

    const showPlay = active && (!soundOn || !playing);

    return (
        <div className="relative h-full w-full overflow-hidden bg-black text-white md:aspect-[9/16] md:w-auto md:rounded-2xl">
            {/* Reveal stage (non-interactive; the tap layer above drives play/pause) */}
            <div className="pointer-events-none flex h-full w-full items-center justify-center p-3 pb-40">
                {active ? (
                    <AnimatedRevealPreview
                        ref={ref}
                        key={short.node_id}
                        bare
                        autoplay
                        fragments={short.fragments}
                        onPlayingChange={setPlaying}
                        stageClass="h-full w-full max-h-full"
                    />
                ) : (
                    <ShortPoster short={short} />
                )}
            </div>

            {/* Tap layer: play/pause + sound-enable */}
            {active && (
                <button onClick={onTap} className="absolute inset-0 z-10 flex flex-col items-center justify-center" aria-label={playing ? 'Pause' : 'Play'}>
                    {showPlay && (
                        <>
                            <span className="grid h-16 w-16 place-items-center rounded-full bg-black/45 text-3xl backdrop-blur">▶</span>
                            {!soundOn && <span className="mt-3 rounded-full bg-black/45 px-3 py-1 text-xs font-semibold">Tap to play with sound</span>}
                        </>
                    )}
                </button>
            )}

            {/* Bottom overlay: title, course, CTA + share (above the tap layer) */}
            <div className="pointer-events-none absolute inset-x-0 bottom-0 z-20 bg-gradient-to-t from-black/85 via-black/50 to-transparent p-5 pt-16">
                {short.subject && (
                    <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: t.tint }}>{short.subject}</span>
                )}
                <h3 className="mt-1 font-display text-lg font-semibold leading-snug text-balance">{short.title}</h3>
                <p className="mt-0.5 text-sm text-white/60 tabular-nums">
                    {short.course_title} · {short.views.toLocaleString()} view{short.views === 1 ? '' : 's'}
                </p>
                <div className="pointer-events-auto mt-4 flex items-center gap-3">
                    <Link
                        href={`/courses/${short.course_slug}`}
                        className="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black transition hover:bg-white/90"
                    >
                        Watch full course →
                    </Link>
                    <ShareButton
                        url={`/shorts?s=${short.node_id}`}
                        title={short.title}
                        text={`${short.title} — a short from “${short.course_title}” on Samchita`}
                        className="inline-flex items-center gap-2 rounded-full border border-white/30 px-4 py-2.5 text-sm font-semibold text-white transition hover:border-white/60"
                    />
                </div>
            </div>
        </div>
    );
}

function ShortPoster({ short }: { short: Short }) {
    const first = short.fragments[0];
    const src = first?.svg ? svgDataUri(first.svg) : first?.image_url;
    return (
        <div className="flex h-full flex-col items-center justify-center gap-5 p-6 text-center">
            {src ? <img src={src} alt="" className="max-h-[45%] max-w-full object-contain opacity-90" /> : null}
            <p className="font-display text-xl font-semibold text-white/90 text-balance">{short.title}</p>
        </div>
    );
}

function NavBtn({ dir, onClick, disabled }: { dir: string; onClick: () => void; disabled: boolean }) {
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className="pointer-events-auto grid h-11 w-11 place-items-center rounded-full bg-white/10 text-lg text-white transition hover:bg-white/20 disabled:opacity-30"
        >
            {dir}
        </button>
    );
}
