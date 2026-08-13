import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
    type CSSProperties,
    type ReactNode,
} from 'react';

export type Fragment = { md: string; effect?: string; voice?: string; duration_ms?: number };

/** Imperative control surface so a parent (e.g. the lesson player) can drive
 *  Play/Pause from its own footer instead of the in-card controls. */
export type RevealHandle = { toggle: () => void; restart: () => void; isPlaying: () => boolean };

function plainText(md: string): string {
    return md
        .replace(/[#>*_`~]/g, '')
        .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/\s+/g, ' ')
        .trim();
}

/** Minimal markdown for one beat: #/##/### headings, - bullets, **bold** / *italic*. */
function Md({ md }: { md: string }) {
    const raw = md.trimEnd();
    const heading = /^(#{1,3})\s+(.*)$/.exec(raw);
    if (heading) {
        const level = (heading[1] ?? '#').length;
        const cls = ['text-xl font-bold', 'text-lg font-semibold', 'text-base font-semibold'][level - 1] ?? 'text-base font-semibold';
        return <p className={cls}>{inline(heading[2] ?? '')}</p>;
    }
    const bullet = /^\s*[-*]\s+(.*)$/.exec(raw);
    if (bullet) return <p className="flex gap-2"><span>•</span><span>{inline(bullet[1] ?? '')}</span></p>;
    return <p>{inline(raw)}</p>;
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

    return (
        <div style={entrance(frag.effect, shown)}>
            {typing ? <p>{full.slice(0, typed)}</p> : <Md md={frag.md} />}
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

    function setPlay(v: boolean) {
        playingRef.current = v;
        setPlaying(v);
        onPlayingChange?.(v);
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

    function reveal(i: number, myGen: number) {
        if (myGen !== gen.current) return;
        if (i >= fragments.length) {
            setPlay(false);
            return;
        }
        setRevealed(i);
        let advanced = false;
        speak(fragments[i]?.voice ?? '', () => {
            if (myGen !== gen.current || advanced) return;
            advanced = true;
            reveal(i + 1, myGen);
        });
    }

    function start() {
        window.speechSynthesis?.cancel();
        const myGen = ++gen.current;
        setRevealed(-1);
        setPlay(true);
        reveal(0, myGen);
    }

    function stop() {
        gen.current++;
        window.speechSynthesis?.cancel();
        setPlay(false);
    }

    function revealAll() {
        stop();
        setRevealed(fragments.length - 1);
    }

    useImperativeHandle(ref, () => ({
        toggle: () => (playingRef.current ? stop() : start()),
        restart: () => start(),
        isPlaying: () => playingRef.current,
    }));

    useEffect(() => {
        if (autoplay) start();
        return () => {
            gen.current++;
            window.speechSynthesis?.cancel();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const beats = (
        <div className="space-y-3">
            {fragments.map((f, i) =>
                i <= revealed ? (
                    <Beat key={`${gen.current}-${i}`} frag={f} typing={f.effect === 'typewriter' && i === revealed && playing} />
                ) : null,
            )}
            {revealed < 0 && !bare && <p className="text-sm text-zinc-500">Press play to preview the reveal.</p>}
        </div>
    );

    if (bare) return beats;

    return (
        <div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            {beats}
            <div className="mt-4 flex items-center gap-2">
                <button
                    type="button"
                    onClick={playing ? stop : start}
                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    {playing ? 'Pause' : revealed >= fragments.length - 1 ? 'Replay' : 'Play'}
                </button>
                <div className="flex flex-1 gap-1">
                    {fragments.map((_, i) => (
                        <span key={i} className={`h-1 flex-1 rounded-full ${i <= revealed ? 'bg-indigo-500' : 'bg-zinc-200 dark:bg-zinc-700'}`} />
                    ))}
                </div>
                <button type="button" onClick={revealAll} className="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    Reveal all
                </button>
            </div>
        </div>
    );
});

export default AnimatedRevealPreview;
