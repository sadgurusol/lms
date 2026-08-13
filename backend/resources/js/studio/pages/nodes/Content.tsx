import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { BlockView } from '@/studio/components/BlockView';
import { useConfirm } from '@/studio/components/ConfirmDialog';
import LessonBuilder from '@/studio/components/LessonBuilder';
import LessonPlayer from '@/studio/components/LessonPlayer';

const MEDIA_TYPES = ['image', 'attachment', 'video'];

/** Laravel's XSRF cookie, decoded for the X-XSRF-TOKEN header on a raw fetch. */
function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

/** Upload a file to the studio media endpoint (multipart, not an Inertia call). */
async function uploadMedia(file: File): Promise<{ id: string; url: string; kind: string; filename: string }> {
    const body = new FormData();
    body.append('file', file);
    const res = await fetch('/studio/media', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body,
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((data as { message?: string }).message ?? 'Upload failed');
    return data as { id: string; url: string; kind: string; filename: string };
}

async function sha256Hex(file: File): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
    return Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

async function jsonPost(url: string, body: unknown): Promise<Record<string, unknown>> {
    const res = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(body),
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((data as { message?: string }).message ?? 'Request failed');
    return data as Record<string, unknown>;
}

/**
 * Upload a video via the presigned flow (request → upload → complete), then poll
 * until the transcode finishes. Returns the ready media id. In dev the transcode
 * is a manual `php artisan media:ready`, so this may poll for a while.
 */
async function uploadVideo(file: File, onStatus: (s: string) => void): Promise<string> {
    onStatus('Preparing…');
    const req = (await jsonPost('/studio/media/uploads', {
        filename: file.name,
        mime: file.type || 'video/mp4',
        size_bytes: file.size,
    })) as { id: string; upload_url: string; headers: Record<string, string> };

    onStatus('Uploading…');
    const put = await fetch(req.upload_url, {
        method: 'PUT',
        headers: { ...req.headers, 'X-XSRF-TOKEN': xsrfToken() },
        body: file,
        credentials: 'same-origin',
    });
    if (!put.ok) throw new Error('Upload failed');

    onStatus('Processing…');
    await jsonPost(`/studio/media/uploads/${req.id}/complete`, { checksum: await sha256Hex(file) });

    for (let attempt = 0; attempt < 60; attempt++) {
        const res = await fetch(`/studio/media/${req.id}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const media = (await res.json().catch(() => ({}))) as { status?: string };
        if (media.status === 'ready') return req.id;
        if (media.status === 'failed') throw new Error('Transcoding failed.');
        await new Promise((resolve) => setTimeout(resolve, 2000));
    }
    throw new Error('Still processing. In dev, run: php artisan media:ready');
}

/* ----------------------------------------------------------------------------
 * Portable Text is an array of blocks. We author paragraphs only for now — one
 * line per block, style "normal". The stored shape is the real one, so a richer
 * editor later reads and writes the same documents without a migration.
 * ------------------------------------------------------------------------- */

type Span = { _type: 'span'; text: string; marks?: string[] };
type PtBlock = { _type: 'block'; style?: string; markDefs?: unknown[]; children: Span[] };

function bodyToText(body: unknown): string {
    if (!Array.isArray(body)) return '';
    return body
        .map((block) => {
            const children = (block as PtBlock)?.children;
            if (!Array.isArray(children)) return '';
            return children.map((span) => span?.text ?? '').join('');
        })
        .join('\n');
}

function textToBody(text: string): PtBlock[] {
    return text
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => ({
            _type: 'block',
            style: 'normal',
            markDefs: [],
            children: [{ _type: 'span', text: line, marks: [] }],
        }));
}

/* ------------------------------------------------------------------------- */

type Block = { id: string; type: string; payload: Record<string, unknown> };

type Props = {
    node: { id: string; title: string; level_name: string; allows_content: boolean };
    course: { id: string; title: string };
    blocks: Block[];
    addable_types: string[];
    can: { edit: boolean; build_lesson?: boolean };
};

const opts = { preserveScroll: true, preserveState: true } as const;

const TYPE_LABEL: Record<string, string> = {
    rich_text: 'Text',
    callout: 'Callout',
    embed: 'Embed',
    image: 'Image',
    attachment: 'File',
    video: 'Video',
};

export default function NodeContent({ node, course, blocks, addable_types, can }: Props) {
    const [showBuilder, setShowBuilder] = useState(false);
    const [showPlayer, setShowPlayer] = useState(false);
    return (
        <StudioLayout title={`${node.title} · content`}>
            <Head title={`${node.title} · content`} />

            {can.build_lesson && (
                <div className="mb-4 flex items-center justify-between rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 dark:border-indigo-900/50 dark:bg-indigo-950/30">
                    <p className="text-sm text-indigo-900 dark:text-indigo-200">
                        ✨ Generate an <strong>animated lesson</strong> — build steps one at a time with AI (animated reveals + simulations).
                    </p>
                    <div className="flex shrink-0 gap-2">
                        <button
                            onClick={() => setShowPlayer(true)}
                            className="rounded-md border border-indigo-300 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-900/40"
                        >
                            ▶ Preview
                        </button>
                        <button
                            onClick={() => setShowBuilder(true)}
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            Open builder
                        </button>
                    </div>
                </div>
            )}

            {showBuilder && (
                <LessonBuilder
                    lessonNodeId={node.id}
                    lessonTitle={node.title}
                    courseId={course.id}
                    onClose={() => setShowBuilder(false)}
                />
            )}

            {showPlayer && <LessonPlayer lessonNodeId={node.id} title={node.title} onClose={() => setShowPlayer(false)} />}

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href={`/studio/courses/${course.id}`} className="hover:underline">
                    ← {course.title}
                </Link>
                <span aria-hidden>·</span>
                <span>
                    {node.level_name}: {node.title}
                </span>
            </div>

            {!can.edit && (
                <p className="mb-6 rounded-md border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
                    You can view this content but not edit it.
                </p>
            )}

            {blocks.length === 0 && (
                <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
                    No content yet. Add a block to begin.
                </p>
            )}

            <div className="space-y-3">
                {blocks.map((block, index) => (
                    <BlockCard
                        key={block.id}
                        block={block}
                        index={index}
                        blocks={blocks}
                        editable={can.edit}
                    />
                ))}
            </div>

            {can.edit && addable_types.length > 0 && (
                <div className="mt-4">
                    <AddBlockMenu nodeId={node.id} types={addable_types} />
                </div>
            )}
        </StudioLayout>
    );
}

function BlockCard({
    block,
    index,
    blocks,
    editable,
}: {
    block: Block;
    index: number;
    blocks: Block[];
    editable: boolean;
}) {
    const confirm = useConfirm();
    const isFirst = index === 0;
    const isLast = index === blocks.length - 1;

    function move(direction: 'up' | 'down') {
        const afterId =
            direction === 'up'
                ? (blocks[index - 2]?.id ?? null)
                : (blocks[index + 1]?.id ?? null);
        router.post(`/studio/content-blocks/${block.id}/move`, { after_block_id: afterId }, opts);
    }

    async function destroy() {
        const ok = await confirm({
            title: 'Remove block',
            message: 'Remove this block? Its content will be deleted.',
            confirmLabel: 'Remove',
            danger: true,
        });
        if (ok) router.delete(`/studio/content-blocks/${block.id}`, opts);
    }

    return (
        <div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-3 flex items-center gap-2">
                <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {TYPE_LABEL[block.type] ?? block.type}
                </span>
                {editable && (
                    <div className="ml-auto flex items-center gap-1 text-xs">
                        <button
                            type="button"
                            aria-label="Move up"
                            disabled={isFirst}
                            onClick={() => move('up')}
                            className="rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 disabled:opacity-30 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            aria-label="Move down"
                            disabled={isLast}
                            onClick={() => move('down')}
                            className="rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 disabled:opacity-30 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            onClick={destroy}
                            className="rounded px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                        >
                            Delete
                        </button>
                    </div>
                )}
            </div>

            <BlockEditor block={block} editable={editable} />
        </div>
    );
}

function BlockEditor({ block, editable }: { block: Block; editable: boolean }) {
    switch (block.type) {
        case 'rich_text':
            return <RichTextEditor block={block} editable={editable} />;
        case 'callout':
            return <CalloutEditor block={block} editable={editable} />;
        case 'embed':
            return <EmbedEditor block={block} editable={editable} />;
        case 'image':
        case 'attachment':
            // Media blocks are placed, not text-edited: show the asset. Reorder
            // and delete still apply.
            return <BlockView block={block} />;
        default:
            // AI-generated blocks (animated_reveal / simulation / animation) are
            // authored in the lesson builder, not typed here — show a read-only
            // preview so the step's content is visible. Reorder/delete still apply.
            return (
                <div>
                    <BlockView block={block} />
                    <p className="mt-1 text-xs text-zinc-400">Generated in the lesson builder — reorder or delete here; edit by regenerating.</p>
                </div>
            );
    }
}

/** Shared save wiring: a PATCH that carries the whole payload. */
function useSaveBlock(blockId: string) {
    const errors = usePage().props.errors as Record<string, string>;
    const [saving, setSaving] = useState(false);

    function save(payload: Record<string, unknown>) {
        setSaving(true);
        router.patch(
            `/studio/content-blocks/${blockId}`,
            // The payload is arbitrary block JSON; Inertia serialises it whole.
            // Its FormDataConvertible type cannot express "any JSON", so cast.
            { payload } as unknown as Parameters<typeof router.patch>[1],
            { ...opts, onFinish: () => setSaving(false) },
        );
    }

    return { save, saving, error: errors?.payload };
}

function RichTextEditor({ block, editable }: { block: Block; editable: boolean }) {
    const [text, setText] = useState(() => bodyToText(block.payload.body));
    const { save, saving, error } = useSaveBlock(block.id);

    function submit(event: FormEvent) {
        event.preventDefault();
        save({ format: 'portable_text', body: textToBody(text) });
    }

    return (
        <form onSubmit={submit} className="space-y-2">
            <textarea
                rows={6}
                disabled={!editable}
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder="Write the content. Each line becomes a paragraph."
                className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
            />
            <SaveRow editable={editable} saving={saving} error={error} />
        </form>
    );
}

const CALLOUT_VARIANTS = ['info', 'tip', 'warning', 'danger', 'example'] as const;

function CalloutEditor({ block, editable }: { block: Block; editable: boolean }) {
    const [variant, setVariant] = useState(() => String(block.payload.variant ?? 'info'));
    const [title, setTitle] = useState(() => String(block.payload.title ?? ''));
    const [text, setText] = useState(() => bodyToText(block.payload.body));
    const { save, saving, error } = useSaveBlock(block.id);

    function submit(event: FormEvent) {
        event.preventDefault();
        const payload: Record<string, unknown> = { variant, body: textToBody(text) };
        if (title.trim() !== '') payload.title = title;
        save(payload);
    }

    return (
        <form onSubmit={submit} className="space-y-2">
            <div className="flex gap-2">
                <select
                    disabled={!editable}
                    value={variant}
                    onChange={(e) => setVariant(e.target.value)}
                    className="rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
                >
                    {CALLOUT_VARIANTS.map((v) => (
                        <option key={v} value={v}>
                            {v}
                        </option>
                    ))}
                </select>
                <input
                    disabled={!editable}
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Title (optional)"
                    className="flex-1 rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
                />
            </div>
            <textarea
                rows={3}
                disabled={!editable}
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder="Callout body"
                className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
            />
            <SaveRow editable={editable} saving={saving} error={error} />
        </form>
    );
}

const EMBED_PROVIDERS = ['youtube', 'vimeo', 'geogebra', 'desmos'] as const;
const EMBED_RATIOS = ['16:9', '4:3', '1:1'] as const;

function EmbedEditor({ block, editable }: { block: Block; editable: boolean }) {
    const [provider, setProvider] = useState(() => String(block.payload.provider ?? 'youtube'));
    const [url, setUrl] = useState(() => String(block.payload.url ?? 'https://'));
    const [title, setTitle] = useState(() => String(block.payload.title ?? ''));
    const [ratio, setRatio] = useState(() => String(block.payload.aspect_ratio ?? '16:9'));
    const { save, saving, error } = useSaveBlock(block.id);

    function submit(event: FormEvent) {
        event.preventDefault();
        const payload: Record<string, unknown> = { provider, url, aspect_ratio: ratio };
        if (title.trim() !== '') payload.title = title;
        save(payload);
    }

    return (
        <form onSubmit={submit} className="space-y-2">
            <div className="flex gap-2">
                <select
                    disabled={!editable}
                    value={provider}
                    onChange={(e) => setProvider(e.target.value)}
                    className="rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
                >
                    {EMBED_PROVIDERS.map((p) => (
                        <option key={p} value={p}>
                            {p}
                        </option>
                    ))}
                </select>
                <select
                    disabled={!editable}
                    value={ratio}
                    onChange={(e) => setRatio(e.target.value)}
                    className="rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
                >
                    {EMBED_RATIOS.map((r) => (
                        <option key={r} value={r}>
                            {r}
                        </option>
                    ))}
                </select>
            </div>
            <input
                disabled={!editable}
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                placeholder="https://www.youtube.com/embed/…"
                className="w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
            />
            <input
                disabled={!editable}
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="Title (optional)"
                className="w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
            />
            <SaveRow editable={editable} saving={saving} error={error} />
        </form>
    );
}

function SaveRow({
    editable,
    saving,
    error,
}: {
    editable: boolean;
    saving: boolean;
    error?: string;
}) {
    if (!editable) return null;
    return (
        <div className="flex items-center gap-3">
            <button
                type="submit"
                disabled={saving}
                className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                Save
            </button>
            {error && (
                <span role="alert" className="text-sm text-red-600">
                    {error}
                </span>
            )}
        </div>
    );
}

function AddBlockMenu({ nodeId, types }: { nodeId: string; types: string[] }) {
    const [busy, setBusy] = useState<string | null>(null);
    const [status, setStatus] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    function add(type: string) {
        router.post(`/studio/course-nodes/${nodeId}/content`, { type, after_block_id: null }, opts);
    }

    async function upload(type: string, file: File) {
        setBusy(type);
        setError(null);
        setStatus(null);
        try {
            if (type === 'video') {
                const mediaId = await uploadVideo(file, setStatus);
                router.post(`/studio/course-nodes/${nodeId}/media-blocks`, { type, media_id: mediaId }, opts);
            } else {
                const media = await uploadMedia(file);
                router.post(
                    `/studio/course-nodes/${nodeId}/media-blocks`,
                    { type, media_id: media.id, alt: media.filename },
                    opts,
                );
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Upload failed');
        } finally {
            setBusy(null);
            setStatus(null);
        }
    }

    const accept = (type: string) =>
        type === 'image' ? 'image/*' : type === 'video' ? 'video/*' : 'application/pdf';

    const className =
        'rounded-md border border-dashed border-zinc-300 px-2.5 py-1 text-sm text-zinc-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-zinc-700 dark:text-zinc-400';

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-2">
                {types.map((type) =>
                    MEDIA_TYPES.includes(type) ? (
                        <label key={type} className={`${className} cursor-pointer`}>
                            {busy === type ? (status ?? 'Uploading…') : `+ ${TYPE_LABEL[type] ?? type}`}
                            <input
                                type="file"
                                accept={accept(type)}
                                className="hidden"
                                disabled={busy !== null}
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (file) void upload(type, file);
                                    e.target.value = '';
                                }}
                            />
                        </label>
                    ) : (
                        <button key={type} type="button" onClick={() => add(type)} className={className}>
                            + {TYPE_LABEL[type] ?? type}
                        </button>
                    ),
                )}
            </div>
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}
