import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import LessonPlayer from '@/studio/components/LessonPlayer';
import type { ContentNode } from '../api';
import { getContent } from '../api';
import { useAuthModal } from '../components/AuthModal';
import { useAuth } from '../lib/auth';
import { isPlayable, lessonSteps } from '../lib/lesson';
import { fetchServerProgress, recordCompletion } from '../lib/progress';
import { onTint, soft, subjectTheme } from '../lib/subject';
import { useAsync } from '../lib/useAsync';
import { usePageTitle } from '../lib/usePageTitle';
import { Link } from '../router';

const hasGrandchildren = (n: ContentNode) => (n.children ?? []).some((k) => (k.children?.length ?? 0) > 0);
const isSection = (n: ContentNode) => hasGrandchildren(n);
// A locked lesson has its content stripped, so recognise it by the flag too.
const isLesson = (n: ContentNode) => !isSection(n) && (isPlayable(n) || !!n.locked);

export default function Learn({ slug }: { slug: string }) {
    const { loading, data, error } = useAsync(() => getContent(slug), [slug]);
    const { user } = useAuth();
    const { open: openAuth } = useAuthModal();
    const [open, setOpen] = useState<ContentNode | null>(null);
    const [done, setDone] = useState<Set<string>>(() => loadProgress(slug));
    const [drawerOpen, setDrawerOpen] = useState(false);
    const merged = useRef(false);

    // When signed in, merge server progress with this device's local progress
    // (pushing any local-only completions up) so it follows the learner across
    // devices. Runs once the course + user are both known.
    useEffect(() => {
        if (!user || !data || merged.current) return;
        merged.current = true;
        void (async () => {
            try {
                const server = await fetchServerProgress(slug);
                const local = loadProgress(slug);
                const union = new Set<string>([...server, ...local]);
                for (const id of local) {
                    if (!server.includes(id)) {
                        try {
                            await recordCompletion(slug, id);
                        } catch {
                            /* stale id / offline — ignore */
                        }
                    }
                }
                setDone(union);
                saveProgress(slug, union);
            } catch {
                /* not signed in / offline — keep local */
            }
        })();
    }, [user, data, slug]);

    const lessons = useMemo(() => (data ? collectLessons(data.tree) : []), [data]);
    // Continue points at the first unfinished *free* lesson (then any unfinished).
    const nextUp = lessons.find((l) => !done.has(l.id) && !l.locked) ?? lessons.find((l) => !done.has(l.id)) ?? lessons[0];
    const t = subjectTheme(data?.course.subject);
    usePageTitle(data?.course.title);

    if (loading) return <FullMessage>Loading course…</FullMessage>;
    if (error || !data) {
        return (
            <FullMessage>
                This course isn’t available.
                <Link href="/courses" className="mt-4 text-sm font-semibold text-[var(--accent)]">← Back to courses</Link>
            </FullMessage>
        );
    }

    function markDone(node: ContentNode) {
        const next = new Set(done);
        next.add(node.id);
        setDone(next);
        saveProgress(slug, next);
        if (user) void recordCompletion(slug, node.id).catch(() => {});
    }

    return (
        <div className="flex min-h-screen flex-col bg-[var(--paper)] md:flex-row">
            {/* Mobile top bar */}
            <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-[var(--line)] bg-[var(--paper)] px-4 py-3 md:hidden">
                <Link href={`/courses/${slug}`} className="text-lg text-[var(--muted)]" aria-label="Back to course">←</Link>
                <span className="min-w-0 flex-1 truncate font-display font-semibold">{data.course.title}</span>
                <button onClick={() => setDrawerOpen(true)} className="shrink-0 rounded-full border border-[var(--line)] px-3 py-1.5 text-xs font-semibold">
                    ☰ Lessons
                </button>
            </header>

            {/* Sidebar: course outline (desktop) */}
            <aside className="hidden w-80 shrink-0 flex-col border-r border-[var(--line)] bg-[var(--card)] md:flex">
                <div className="border-b border-[var(--line)] p-5">
                    <Link href={`/courses/${slug}`} className="text-xs font-semibold text-[var(--accent)]">← Course</Link>
                    <h1 className="mt-2 font-display text-lg font-semibold leading-snug">{data.course.title}</h1>
                    <p className="mt-1 text-xs text-[var(--muted)]">
                        {done.size}/{lessons.length} lessons done
                    </p>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--line)]">
                        <div className="h-full rounded-full transition-all" style={{ width: lessons.length ? `${(done.size / lessons.length) * 100}%` : '0%', background: t.tint }} />
                    </div>
                </div>
                <nav className="flex-1 overflow-y-auto p-3">
                    <Tree nodes={data.tree} done={done} onOpen={setOpen} activeId={open?.id ?? null} />
                </nav>
            </aside>

            {/* Main */}
            <main className="flex flex-1 items-center justify-center p-6">
                <div className="max-w-lg text-center">
                    <span
                        className="mx-auto grid h-16 w-16 place-items-center rounded-2xl font-display text-3xl font-semibold"
                        style={{ background: soft(t.tint, 16), color: t.tint }}
                    >
                        {(data.course.subject ?? data.course.title).trim().charAt(0).toUpperCase()}
                    </span>
                    <p className="mt-5 text-sm font-semibold uppercase tracking-[0.2em]" style={{ color: t.tint }}>
                        {data.course.subject ?? 'Course'}
                    </p>
                    <h2 className="mt-2 font-display text-3xl font-semibold leading-tight text-balance">{data.course.title}</h2>
                    <p className="mt-3 text-[var(--muted)]">
                        {lessons.length} lesson{lessons.length === 1 ? '' : 's'}. Pick one from the list, or continue where you left off.
                    </p>
                    {nextUp && (
                        <button
                            onClick={() => setOpen(nextUp)}
                            className="mt-8 rounded-full px-7 py-3 text-sm font-semibold shadow-sm transition hover:opacity-90"
                            style={{ background: t.tint, color: onTint(t.tint) }}
                        >
                            {done.size ? 'Continue' : 'Start'}: {nextUp.title}
                        </button>
                    )}
                    {!user && (
                        <p className="mt-6 text-xs text-[var(--muted)]">
                            <button onClick={() => openAuth('signin')} className="font-semibold text-[var(--accent)]">Sign in</button> to save your progress across devices.
                        </p>
                    )}
                </div>
            </main>

            {/* Mobile drawer */}
            {drawerOpen && (
                <div className="fixed inset-0 z-40 md:hidden">
                    <div className="absolute inset-0 bg-black/40" onClick={() => setDrawerOpen(false)} />
                    <div className="absolute inset-y-0 left-0 flex w-80 max-w-[85%] flex-col bg-[var(--card)] shadow-2xl">
                        <div className="flex items-start justify-between gap-2 border-b border-[var(--line)] p-5">
                            <div className="min-w-0">
                                <h2 className="truncate font-display text-lg font-semibold leading-snug">{data.course.title}</h2>
                                <p className="mt-1 text-xs text-[var(--muted)]">{done.size}/{lessons.length} lessons done</p>
                            </div>
                            <button onClick={() => setDrawerOpen(false)} className="rounded-lg p-1 text-[var(--muted)] hover:text-[var(--ink)]" aria-label="Close">✕</button>
                        </div>
                        <nav className="flex-1 overflow-y-auto p-3">
                            <Tree
                                nodes={data.tree}
                                done={done}
                                onOpen={(n) => {
                                    setOpen(n);
                                    setDrawerOpen(false);
                                }}
                                activeId={open?.id ?? null}
                            />
                        </nav>
                    </div>
                </div>
            )}

            {open && !open.locked && (
                <LessonPlayer
                    title={open.title}
                    steps={lessonSteps(open)}
                    onClose={() => {
                        markDone(open);
                        setOpen(null);
                    }}
                />
            )}
            {open && open.locked && <Wall title={open.title} tint={t.tint} onClose={() => setOpen(null)} />}
        </div>
    );
}

/** Shown when a learner opens a lesson beyond the free preview. */
function Wall({ title, tint, onClose }: { title: string; tint: string; onClose: () => void }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-6">
            <div className="w-full max-w-md rounded-2xl bg-[var(--card)] p-8 text-center shadow-2xl">
                <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl text-2xl" style={{ background: soft(tint, 16) }}>🔒</span>
                <h3 className="mt-5 font-display text-2xl font-semibold">This lesson is part of the full course</h3>
                <p className="mt-2 text-sm text-[var(--muted)] text-pretty">“{title}” is beyond the free preview. The rest of this course will unlock soon.</p>
                <button onClick={onClose} className="mt-6 rounded-full px-6 py-2.5 text-sm font-semibold" style={{ background: tint, color: onTint(tint) }}>
                    Keep exploring
                </button>
            </div>
        </div>
    );
}

function Tree({
    nodes,
    done,
    onOpen,
    activeId,
    depth = 0,
}: {
    nodes: ContentNode[];
    done: Set<string>;
    onOpen: (n: ContentNode) => void;
    activeId: string | null;
    depth?: number;
}) {
    return (
        <div className="space-y-0.5">
            {nodes.map((n) => {
                if (isLesson(n)) {
                    const isDone = done.has(n.id);
                    return (
                        <button
                            key={n.id}
                            onClick={() => onOpen(n)}
                            style={{ paddingLeft: depth * 14 + 12 }}
                            className={`flex w-full items-center gap-2.5 rounded-lg py-2 pr-3 text-left text-sm transition-colors ${
                                activeId === n.id ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'hover:bg-[var(--paper)]'
                            } ${n.locked ? 'text-[var(--muted)]' : ''}`}
                        >
                            {n.locked ? (
                                <span className="grid h-4 w-4 shrink-0 place-items-center text-[11px] text-[var(--muted)]" aria-label="Locked">🔒</span>
                            ) : (
                                <span
                                    className={`grid h-4 w-4 shrink-0 place-items-center rounded-full border text-[10px] ${
                                        isDone ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--on-accent)]' : 'border-[var(--line)] text-transparent'
                                    }`}
                                >
                                    ✓
                                </span>
                            )}
                            <span className="truncate">{n.title}</span>
                        </button>
                    );
                }
                if (isSection(n)) {
                    return (
                        <div key={n.id} className="pt-2">
                            <p
                                style={{ paddingLeft: depth * 14 + 12 }}
                                className="py-1 font-display text-sm font-semibold text-[var(--ink)]"
                            >
                                {n.number ? `${n.number}. ` : ''}
                                {n.title}
                            </p>
                            <Tree nodes={n.children ?? []} done={done} onOpen={onOpen} activeId={activeId} depth={depth + 1} />
                        </div>
                    );
                }
                return null;
            })}
        </div>
    );
}

function FullMessage({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-2 bg-[var(--paper)] px-6 text-center text-[var(--muted)]">
            {children}
        </div>
    );
}

/** The ordered list of openable lessons (for progress + continue). */
function collectLessons(tree: ContentNode[]): ContentNode[] {
    const out: ContentNode[] = [];
    const walk = (nodes: ContentNode[]) => {
        for (const n of nodes) {
            if (isLesson(n)) out.push(n);
            else walk(n.children ?? []);
        }
    };
    walk(tree);
    return out;
}

function loadProgress(slug: string): Set<string> {
    try {
        const raw = localStorage.getItem(`portal:progress:${slug}`);
        return new Set(raw ? (JSON.parse(raw) as string[]) : []);
    } catch {
        return new Set();
    }
}

function saveProgress(slug: string, set: Set<string>) {
    try {
        localStorage.setItem(`portal:progress:${slug}`, JSON.stringify([...set]));
    } catch {
        /* private mode / disabled storage — progress just won't persist */
    }
}
