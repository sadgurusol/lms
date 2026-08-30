import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
    type CSSProperties,
    type ReactNode,
} from 'react';

export type Fragment = {
    md: string;
    effect?: string;
    voice?: string;
    audio_url?: string;
    duration_ms?: number;
    // Per-beat visual, revealed with the beat: an inline SVG (may be SMIL-animated)
    // and/or an image/gif URL.
    svg?: string;
    image_url?: string;
    alt?: string;
};

/** Imperative control surface so a parent (e.g. the lesson player) can drive
 *  Play/Pause from its own footer instead of the in-card controls. */
export type RevealHandle = { toggle: () => void; restart: () => void; isPlaying: () => boolean };

/** An SVG rendered via <img> needs the xmlns on its root or it won't display —
 *  inject it when missing (older content lacks it), then encode as a data URI. */
export function svgDataUri(svg: string): string {
    const withNs = /<svg\b[^>]*\bxmlns=/i.test(svg)
        ? svg
        : svg.replace(/<svg\b/i, '<svg xmlns="http://www.w3.org/2000/svg"');
    return `data:image/svg+xml;utf8,${encodeURIComponent(withNs)}`;
}

function plainText(md: string): string {
    return md
        .replace(/[#>*_`~]/g, '')
        .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/\s+/g, ' ')
        .trim();
}

/** Minimal markdown for one beat, line by line: #/##/### headings, -/* and
 *  numbered bullets (grouped into a list), paragraphs, **bold** / *italic*. A
 *  beat is often several lines, so this must not treat the whole string as one. */
function Md({ md }: { md: string }) {
    const out: ReactNode[] = [];
    let bullets: ReactNode[] = [];
    let k = 0;
    const flush = () => {
        if (bullets.length) {
            out.push(
                <ul key={`u${k++}`} className="space-y-1">
                    {bullets}
                </ul>,
            );
            bullets = [];
        }
    };

    for (const line of md.replace(/\r/g, '').split('\n')) {
        const raw = line.trimEnd();
        if (!raw.trim()) {
            flush();
            continue;
        }
        const heading = /^(#{1,3})\s+(.*)$/.exec(raw);
        if (heading) {
            flush();
            const level = (heading[1] ?? '#').length;
            const cls =
                ['text-xl font-bold', 'text-lg font-semibold', 'text-base font-semibold'][level - 1] ??
                'text-base font-semibold';
            out.push(
                <p key={`h${k++}`} className={cls}>
                    {inline(heading[2] ?? '')}
                </p>,
            );
            continue;
        }
        const bullet = /^\s*(?:[-*]|\d+\.)\s+(.*)$/.exec(raw);
        if (bullet) {
            const marker = /^\s*(\d+)\./.exec(raw)?.[1];
            bullets.push(
                <li key={`b${k++}`} className="flex gap-2">
                    <span className="shrink-0">{marker ? `${marker}.` : '•'}</span>
                    <span>{inline(bullet[1] ?? '')}</span>
                </li>,
            );
            continue;
        }
        flush();
        out.push(<p key={`p${k++}`}>{inline(raw)}</p>);
    }
    flush();

    return <div className="space-y-1.5">{out}</div>;
}

function inline(text: string): ReactNode[] {
    const out: ReactNode[] = [];
    const re = /(\*\*(.+?)\*\*)|(\*(.+?)\*)|(_(.+?)_)/g;
    let last = 0;
    let m: RegExpExecArray | null;
    let k = 0;
    while ((m = re.exec(text)) !== null) {
        if (m.index > last) out.push(text.slice(last, m.index));
        if (m[2] != null) out.push(<strong key={k++}>{m[2]}</strong>);
        else out.push(<em key={k++}>{m[4] ?? m[6]}</em>);
        last = re.lastIndex;
    }
    if (last < text.length) out.push(text.slice(last));
    return out;
}

function entrance(effect: string | undefined, shown: boolean): CSSProperties {
    const base: CSSProperties = { transition: 'opacity 400ms ease, transform 400ms ease', opacity: shown ? 1 : 0 };
    if (shown) return { ...base, transform: 'none' };
    switch (effect) {
        case 'slide-up':
            return { ...base, transform: 'translateY(16px)' };
        case 'slide-left':
            return { ...base, transform: 'translateX(24px)' };
        case 'zoom':
            return { ...base, transform: 'scale(0.92)' };
        default:
            return base;
    }
}

function Beat({ frag, typing }: { frag: Fragment; typing: boolean }) {
    const [shown, setShown] = useState(false);
    const [typed, setTyped] = useState(typing ? 0 : -1);
    const full = typing ? plainText(frag.md) : '';

    useEffect(() => {
        const id = requestAnimationFrame(() => setShown(true));
        return () => cancelAnimationFrame(id);
    }, []);

    useEffect(() => {
        if (!typing) return;
        let n = 0;
        const t = setInterval(() => {
            n += 1;
            setTyped(n);
            if (n >= full.length) clearInterval(t);
        }, 28);
        return () => clearInterval(t);
    }, [typing, full.length]);

    // One beat fills the stage: text sits above, the visual takes the remaining
    // height and scales to fit — so nothing overflows into a hidden scroll area.
    return (
        <div
            style={entrance(frag.effect, shown)}
            className="absolute inset-0 flex flex-col items-center justify-center gap-4 p-6 text-center md:p-10"
        >
            <div className="w-full shrink-0 overflow-hidden">
                {typing ? <p>{full.slice(0, typed)}</p> : <Md md={frag.md} />}
            </div>
            <FragmentVisual frag={frag} />
        </div>
    );
}

/** The beat's own visual: an inline SVG (rendered via an <img> data-URI so it is
 *  script-inert yet still runs SMIL animations) or an image/gif URL. Fills the
 *  remaining stage height and scales down to fit (object-contain). */
function FragmentVisual({ frag }: { frag: Fragment }) {
    const src = frag.svg ? svgDataUri(frag.svg) : frag.image_url;
    if (!src) return null;
    return (
        <div className="flex min-h-0 w-full flex-1 items-center justify-center">
            <img src={src} alt={frag.alt ?? ''} className="max-h-full max-w-full rounded-md object-contain" />
        </div>
    );
}

type Props = {
    fragments: Fragment[];
    /** No card wrapper and no in-card controls — the parent supplies the stage + controls. */
    bare?: boolean;
    /** Start playing on mount (used by the player, which arrives via a click gesture). */
    autoplay?: boolean;
    onPlayingChange?: (playing: boolean) => void;
};

/** Plays an animated reveal in the browser: beats appear one at a time, narrated
 *  with the Web Speech API. In `bare` mode the parent (lesson player) owns the
 *  container and the Play/Pause button via the imperative RevealHandle. */
const AnimatedRevealPreview = forwardRef<RevealHandle, Props>(function AnimatedRevealPreview(
    { fragments, bare = false, autoplay = false, onPlayingChange },
    ref,
) {
    const [revealed, setRevealed] = useState(-1);
    const [playing, setPlaying] = useState(false);
    const playingRef = useRef(false);
    const gen = useRef(0);
    // Bumped only on a fresh start/replay so resuming after a pause doesn't
    // re-mount (and re-animate) the beats that are already on screen.
    const runId = useRef(0);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    function setPlay(v: boolean) {
        playingRef.current = v;
        setPlaying(v);
        onPlayingChange?.(v);
    }

    function stopAudio() {
        if (audioRef.current) {
            audioRef.current.pause();
            audioRef.current.src = '';
            audioRef.current = null;
        }
    }

    function speak(text: string, onEnd: () => void) {
        const synth = window.speechSynthesis;
        if (!synth || !text) {
            window.setTimeout(onEnd, 400);
            return;
        }
        const u = new SpeechSynthesisUtterance(text);
        u.onend = onEnd;
        synth.speak(u);
        window.setTimeout(onEnd, Math.max(2500, (text.length / 11) * 1000 + 1500));
    }

    // Narrate one beat: prefer the pre-generated audio file (consistent, natural
    // voice); fall back to on-device speech only when a fragment has no audio.
    function narrate(frag: Fragment, onEnd: () => void) {
        if (frag.audio_url) {
            stopAudio();
            const a = new Audio(frag.audio_url);
            audioRef.current = a;
            let done = false;
            const fin = () => {
                if (done) return;
                done = true;
                onEnd();
            };
            a.addEventListener('ended', fin);
            a.addEventListener('error', fin);
            a.play().catch(() => {});
            window.setTimeout(fin, 60000); // ultimate fallback if it stalls
            return;
        }
        speak(frag.voice ?? '', onEnd);
    }

    function reveal(i: number, myGen: number) {
        if (myGen !== gen.current) return;
        if (i >= fragments.length) {
            setPlay(false);
            return;
        }
        setRevealed(i);
        let advanced = false;
        narrate(fragments[i] ?? { md: '' }, () => {
            if (myGen !== gen.current || advanced) return;
            advanced = true;
            reveal(i + 1, myGen);
        });
    }

    function start() {
        window.speechSynthesis?.cancel();
        stopAudio();
        runId.current++;
        const myGen = ++gen.current;
        setRevealed(-1);
        setPlay(true);
        reveal(0, myGen);
    }

    // Pause: hold the current beat (keeps `revealed`), just stop the narration.
    function stop() {
        gen.current++;
        window.speechSynthesis?.cancel();
        stopAudio();
        setPlay(false);
    }

    // Resume from the current beat (re-narrates it) — not from the beginning.
    function resume() {
        if (revealed < 0) return start();
        const myGen = ++gen.current;
        setPlay(true);
        reveal(revealed, myGen);
    }

    function revealAll() {
        stop();
        setRevealed(fragments.length - 1);
    }

    useImperativeHandle(ref, () => ({
        // Play/pause from the parent's footer: pause holds, play resumes, and a
        // finished reveal replays from the top.
        toggle: () =>
            playingRef.current ? stop() : revealed >= fragments.length - 1 ? start() : resume(),
        restart: () => start(),
        isPlaying: () => playingRef.current,
    }));

    useEffect(() => {
        if (autoplay) start();
        return () => {
            gen.current++;
            window.speechSynthesis?.cancel();
            stopAudio();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // One beat on screen at a time, in a fixed aspect-ratio canvas (like a video),
    // so a beat's image never pushes content into a hidden scroll area.
    const cur = revealed >= 0 ? fragments[revealed] ?? null : null;
    const stage = (
        <div className="relative mx-auto aspect-video max-h-full w-full max-w-4xl overflow-hidden rounded-lg bg-black/[0.02] dark:bg-white/[0.03]">
            {cur ? (
                <Beat
                    key={`${runId.current}-${revealed}`}
                    frag={cur}
                    typing={cur.effect === 'typewriter' && playing}
                />
            ) : (
                <div className="flex h-full items-center justify-center text-sm text-zinc-500">
                    {bare ? '' : 'Press play to preview the reveal.'}
                </div>
            )}
        </div>
    );

    if (bare) return stage;

    return (
        <div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            {stage}
            <div className="mt-4 flex items-center gap-2">
                <button
                    type="button"
                    onClick={playing ? stop : revealed >= fragments.length - 1 ? start : resume}
                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    {playing ? 'Pause' : revealed >= fragments.length - 1 ? 'Replay' : revealed < 0 ? 'Play' : 'Resume'}
                </button>
                <div className="flex flex-1 gap-1">
                    {fragments.map((_, i) => (
                        <span key={i} className={`h-1 flex-1 rounded-full ${i <= revealed ? 'bg-indigo-500' : 'bg-zinc-200 dark:bg-zinc-700'}`} />
                    ))}
                </div>
                <button type="button" onClick={revealAll} className="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    Skip to end
                </button>
            </div>
        </div>
    );
});

export default AnimatedRevealPreview;
