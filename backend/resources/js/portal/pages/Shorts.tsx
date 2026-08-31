import { useEffect, useRef, useState } from 'react';
import AnimatedRevealPreview, { svgDataUri } from '@/studio/components/AnimatedRevealPreview';
import { getShorts, recordShortView, type Short } from '../api';
import { ShareButton } from '../components/ShareButton';
import { subjectTheme } from '../lib/subject';
import { useAsync } from '../lib/useAsync';
import { usePageTitle } from '../lib/usePageTitle';
import { Link } from '../router';

export default function Shorts() {
    usePageTitle('Shorts');
    const { loading, data } = useAsync(getShorts, []);
    const shorts = data ?? [];
    const [active, setActive] = useState(0);
    const [audioReady, setAudioReady] = useState(false);
    const viewed = useRef<Set<string>>(new Set());
    const containerRef = useRef<HTMLDivElement>(null);

    // Which short is on screen — via IntersectionObserver, which is reliable on
    // touch scrolling where scroll math (viewport/URL-bar changes) is not.
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

            <div
                ref={containerRef}
                onPointerDown={() => setAudioReady(true)}
                className="h-full snap-y snap-mandatory overflow-y-auto overscroll-contain"
            >
                {shorts.map((s, i) => (
                    <section key={s.node_id} className="flex h-full snap-start snap-always items-center justify-center">
                        <ShortCard short={s} active={i === active} audioReady={audioReady} />
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

function ShortCard({ short, active, audioReady }: { short: Short; active: boolean; audioReady: boolean }) {
    const t = subjectTheme(short.subject);

    return (
        <div className="relative h-full w-full overflow-hidden bg-black text-white md:aspect-[9/16] md:w-auto md:rounded-2xl">
            <div className="flex h-full w-full items-center justify-center p-3 pb-40">
                {active ? (
                    // Key includes audioReady so the reveal remounts once the viewer
                    // interacts — replaying this beat with sound (autoplay had blocked it).
                    <AnimatedRevealPreview
                        key={`${short.node_id}-${audioReady ? 'a' : 's'}`}
                        bare
                        autoplay
                        fragments={short.fragments}
                        stageClass="h-full w-full max-h-full"
                    />
                ) : (
                    <ShortPoster short={short} />
                )}
            </div>

            {active && !audioReady && (
                <div className="pointer-events-none absolute left-1/2 top-4 -translate-x-1/2 rounded-full bg-white/15 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur">
                    🔊 Tap for sound
                </div>
            )}

            {/* Bottom overlay: title, course, CTA + share */}
            <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/50 to-transparent p-5 pt-16">
                {short.subject && (
                    <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: t.tint }}>{short.subject}</span>
                )}
                <h3 className="mt-1 font-display text-lg font-semibold leading-snug text-balance">{short.title}</h3>
                <p className="mt-0.5 text-sm text-white/60 tabular-nums">
                    {short.course_title} · {short.views.toLocaleString()} view{short.views === 1 ? '' : 's'}
                </p>
                <div className="mt-4 flex items-center gap-3">
                    <Link
                        href={`/courses/${short.course_slug}`}
                        className="rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black transition hover:bg-white/90"
                    >
                        Watch full course →
                    </Link>
                    <ShareButton
                        url={`/courses/${short.course_slug}`}
                        title={short.course_title}
                        text={`${short.title} — from “${short.course_title}” on Samchita`}
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
            <span className="rounded-full border border-white/20 px-3 py-1 text-xs text-white/60">Scroll to play</span>
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
