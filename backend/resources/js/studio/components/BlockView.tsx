/**
 * Read-only rendering of a content block, shared by the learner preview and the
 * in-place read-only view in the course tree. One renderer, so the two can never
 * drift.
 */

import AnimatedRevealPreview, { type Fragment } from './AnimatedRevealPreview';

type Span = { _type: string; text?: string; marks?: string[] };
type PtBlock = { _type: string; style?: string; listItem?: string; children?: Span[] };

export type Block = { id: string; type: string; payload: Record<string, unknown> };

export function BlockView({ block }: { block: Block }) {
    switch (block.type) {
        case 'rich_text':
            return <PortableText body={block.payload.body} />;
        case 'callout':
            return <Callout payload={block.payload} />;
        case 'embed':
            return <Embed payload={block.payload} />;
        case 'image':
            return <ImageBlock payload={block.payload} />;
        case 'attachment':
            return <Attachment payload={block.payload} />;
        case 'video':
            return <VideoBlock payload={block.payload} />;
        case 'animated_reveal':
            return <AnimatedRevealPreview fragments={(block.payload.fragments as Fragment[] | undefined) ?? []} />;
        case 'simulation':
            return <SimulationBlock payload={block.payload} />;
        case 'animation':
            return <AnimationBlock payload={block.payload} />;
        case 'audio':
            return <AudioBlock payload={block.payload} />;
        default:
            return (
                <div className="rounded-md border border-dashed border-zinc-300 p-4 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    [{block.type} block — not shown]
                </div>
            );
    }
}

function SimulationBlock({ payload }: { payload: Record<string, unknown> }) {
    const url = payload.url as string | undefined;
    if (!url) return <div className="text-sm text-zinc-500">Simulation unavailable.</div>;
    return (
        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800">
            <iframe
                src={url}
                sandbox="allow-scripts"
                title={(payload.title as string) ?? 'Interactive simulation'}
                style={{ width: '100%', aspectRatio: '16 / 10', border: 0, background: '#fff' }}
            />
        </div>
    );
}

function AudioBlock({ payload }: { payload: Record<string, unknown> }) {
    const url = payload.url as string | undefined;
    const transcript = payload.transcript as string | undefined;
    return (
        <div className="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-1 flex items-center gap-1.5 text-xs font-medium text-zinc-500">🔊 Narration</div>
            {url ? (
                <audio src={url} controls className="w-full" />
            ) : (
                <p className="text-xs text-zinc-400">No audio yet — plays with device voice.</p>
            )}
            {transcript && <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{transcript}</p>}
        </div>
    );
}

function AnimationBlock({ payload }: { payload: Record<string, unknown> }) {
    const url = payload.url as string | undefined;
    if (!url) return <div className="text-sm text-zinc-500">Animation unavailable.</div>;
    return (
        <video
            src={url}
            poster={payload.poster_url as string | undefined}
            controls
            className="w-full rounded-lg bg-black"
        />
    );
}

function formatDuration(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}

function VideoBlock({ payload }: { payload: Record<string, unknown> }) {
    const src = payload.src ? String(payload.src) : null;
    const poster = payload.poster ? String(payload.poster) : undefined;
    const duration = typeof payload.duration_s === 'number' ? payload.duration_s : null;

    // A published/preview block carries a playable source. In the editor it does
    // not (the stream is bearer-gated), so we show an informative placeholder.
    if (src) {
        return (
            <video
                controls
                preload="metadata"
                poster={poster}
                src={src}
                className="max-h-[480px] w-full rounded-md bg-black"
            />
        );
    }

    const status = payload.media_status ? String(payload.media_status) : 'ready';
    const filename = payload.filename ? String(payload.filename) : 'Video';
    const badge =
        status === 'ready'
            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
            : status === 'failed'
              ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'
              : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200';

    return (
        <div className="flex items-center gap-3 rounded-md border border-zinc-200 p-3 text-sm dark:border-zinc-800">
            <span className="text-xl">🎬</span>
            <span className="min-w-0 flex-1 truncate font-medium">{filename}</span>
            {duration != null && <span className="text-xs text-zinc-500">{formatDuration(duration)}</span>}
            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${badge}`}>
                {status === 'processing' ? 'processing…' : status}
            </span>
        </div>
    );
}

function ImageBlock({ payload }: { payload: Record<string, unknown> }) {
    const url = payload.url ? String(payload.url) : null;
    const alt = payload.alt ? String(payload.alt) : '';
    const caption = payload.caption ? String(payload.caption) : null;

    if (url === null) {
        return <p className="text-sm text-zinc-400">Image unavailable.</p>;
    }

    return (
        <figure>
            <img src={url} alt={alt} className="max-h-[480px] w-full rounded-md object-contain" />
            {caption && (
                <figcaption className="mt-1 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    {caption}
                </figcaption>
            )}
        </figure>
    );
}

function Attachment({ payload }: { payload: Record<string, unknown> }) {
    const url = payload.url ? String(payload.url) : null;
    const filename = payload.filename ? String(payload.filename) : 'Download';
    const bytes = typeof payload.size_bytes === 'number' ? payload.size_bytes : null;
    const size = bytes === null ? null : `${(bytes / 1024).toFixed(0)} KB`;

    return (
        <a
            href={url ?? '#'}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-3 rounded-md border border-zinc-200 p-3 text-sm hover:border-indigo-400 dark:border-zinc-800"
        >
            <span className="text-xl">📎</span>
            <span className="min-w-0 flex-1 truncate font-medium">{filename}</span>
            {size && <span className="text-xs text-zinc-500">{size}</span>}
        </a>
    );
}

export function PortableText({ body }: { body: unknown }) {
    if (!Array.isArray(body) || body.length === 0) {
        return <p className="text-sm italic text-zinc-400">Empty.</p>;
    }

    return (
        <div className="space-y-3 leading-relaxed">
            {(body as PtBlock[]).map((blk, i) => (
                <PtBlockView key={i} block={blk} />
            ))}
        </div>
    );
}

function PtBlockView({ block }: { block: PtBlock }) {
    const spans = <Spans spans={block.children} />;

    if (block.listItem) {
        return <li className="ml-6 list-disc">{spans}</li>;
    }

    switch (block.style) {
        case 'h2':
            return <h3 className="text-xl font-semibold">{spans}</h3>;
        case 'h3':
            return <h4 className="text-lg font-semibold">{spans}</h4>;
        case 'h4':
            return <h5 className="text-base font-semibold">{spans}</h5>;
        case 'blockquote':
            return (
                <blockquote className="border-l-4 border-zinc-300 pl-4 italic text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                    {spans}
                </blockquote>
            );
        case 'code':
            return (
                <pre className="overflow-x-auto rounded-md bg-zinc-100 p-3 font-mono text-sm dark:bg-zinc-900">
                    {spans}
                </pre>
            );
        default:
            return <p>{spans}</p>;
    }
}

function Spans({ spans }: { spans?: Span[] }) {
    if (!Array.isArray(spans)) return null;

    return (
        <>
            {spans.map((span, i) => {
                let node: React.ReactNode = span.text ?? '';
                const marks = span.marks ?? [];
                if (marks.includes('strong')) node = <strong>{node}</strong>;
                if (marks.includes('em')) node = <em>{node}</em>;
                if (marks.includes('code'))
                    node = <code className="rounded bg-zinc-100 px-1 font-mono text-sm dark:bg-zinc-800">{node}</code>;
                return <span key={i}>{node}</span>;
            })}
        </>
    );
}

const CALLOUT_TONE: Record<string, string> = {
    info: 'border-sky-300 bg-sky-50 dark:border-sky-900 dark:bg-sky-950',
    tip: 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950',
    warning: 'border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950',
    danger: 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950',
    example: 'border-violet-300 bg-violet-50 dark:border-violet-900 dark:bg-violet-950',
};

function Callout({ payload }: { payload: Record<string, unknown> }) {
    const variant = String(payload.variant ?? 'info');
    const title = payload.title ? String(payload.title) : null;

    return (
        <div className={`rounded-md border p-4 ${CALLOUT_TONE[variant] ?? CALLOUT_TONE.info}`}>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {title ?? variant}
            </p>
            <PortableText body={payload.body} />
        </div>
    );
}

const RATIO_PAD: Record<string, string> = { '16:9': '56.25%', '4:3': '75%', '1:1': '100%' };

function Embed({ payload }: { payload: Record<string, unknown> }) {
    const url = String(payload.url ?? '');
    const ratio = String(payload.aspect_ratio ?? '16:9');
    const title = payload.title ? String(payload.title) : 'Embedded content';

    if (!url.startsWith('https://')) {
        return <p className="text-sm text-red-600">Invalid embed URL.</p>;
    }

    return (
        <figure>
            <div
                className="relative w-full overflow-hidden rounded-md bg-black"
                style={{ paddingTop: RATIO_PAD[ratio] ?? '56.25%' }}
            >
                <iframe
                    src={url}
                    title={title}
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                    className="absolute inset-0 h-full w-full border-0"
                />
            </div>
            {payload.title != null && (
                <figcaption className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{title}</figcaption>
            )}
        </figure>
    );
}
