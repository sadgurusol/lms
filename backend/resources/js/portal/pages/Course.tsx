import type { OutlineNode } from '../api';
import { getCourse } from '../api';
import { useAsync } from '../lib/useAsync';
import { onTint, soft, subjectTheme } from '../lib/subject';
import { usePageTitle } from '../lib/usePageTitle';
import { Link } from '../router';

export default function Course({ slug }: { slug: string }) {
    const { loading, data, error } = useAsync(() => getCourse(slug), [slug]);
    usePageTitle(data?.course.title);

    if (loading) return <div className="mx-auto max-w-4xl px-5 py-24 text-[var(--muted)]">Loading…</div>;
    if (error || !data) {
        return (
            <div className="mx-auto max-w-4xl px-5 py-24 text-center">
                <p className="font-display text-2xl font-semibold">Course not available</p>
                <Link href="/courses" className="mt-4 inline-block text-sm font-semibold text-[var(--accent)]">← Back to courses</Link>
            </div>
        );
    }

    const { course, outline, counts } = data;
    const t = subjectTheme(course.subject);
    const initial = (course.subject ?? course.title).trim().charAt(0).toUpperCase();
    const startHref = `/courses/${course.slug}/learn`;

    // Resume: how many lessons this device has completed.
    const doneSet = readDone(slug);
    const doneCount = outlineLessonIds(outline).filter((id) => doneSet.has(id)).length;
    const started = doneCount > 0;

    return (
        <div>
            <section className="relative overflow-hidden border-b border-[var(--line)]" style={{ background: soft(t.tint, 10) }}>
                <span
                    className="pointer-events-none absolute -right-8 -top-10 select-none font-display text-[16rem] font-semibold leading-none opacity-[0.12]"
                    style={{ color: t.tint }}
                    aria-hidden
                >
                    {initial}
                </span>
                <div className="relative mx-auto max-w-4xl px-5 py-16 md:py-20">
                    <Link href="/courses" className="text-sm font-semibold" style={{ color: t.tint }}>← All courses</Link>
                    <div className="mt-5 flex flex-wrap items-center gap-2 text-xs font-semibold">
                        {course.subject && (
                            <span className="rounded-full px-2.5 py-1" style={{ background: t.tint, color: onTint(t.tint) }}>{course.subject}</span>
                        )}
                        {course.grade_band && <span className="text-[var(--muted)]">· {course.grade_band}</span>}
                    </div>
                    <h1 className="mt-3 max-w-2xl font-display text-4xl font-semibold leading-tight text-balance md:text-5xl">{course.title}</h1>
                    <p className="mt-4 text-[var(--muted)]">
                        <b className="font-semibold text-[var(--ink)] tabular-nums">{counts.lessons}</b> lesson{counts.lessons === 1 ? '' : 's'} · Free · No sign-up
                    </p>
                    <Link
                        href={startHref}
                        className="mt-8 inline-flex items-center gap-2 rounded-full px-7 py-3 text-sm font-semibold shadow-sm transition hover:opacity-90"
                        style={{ background: t.tint, color: onTint(t.tint) }}
                    >
                        {started ? 'Continue learning →' : 'Start learning →'}
                    </Link>
                    {started && (
                        <div className="mt-6 max-w-xs">
                            <p className="text-xs font-semibold text-[var(--muted)] tabular-nums">{doneCount} of {counts.lessons} lessons done</p>
                            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full" style={{ background: soft(t.tint, 20) }}>
                                <div className="h-full rounded-full" style={{ width: `${(doneCount / Math.max(counts.lessons, 1)) * 100}%`, background: t.tint }} />
                            </div>
                        </div>
                    )}
                </div>
            </section>

            <section className="mx-auto max-w-4xl px-5 py-12">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="h-5 w-1 rounded-full" style={{ background: t.tint }} />
                        <h2 className="font-display text-2xl font-semibold">What’s inside</h2>
                    </div>
                    {data.access.free_preview != null && (
                        <span className="rounded-full px-3 py-1 text-xs font-semibold" style={{ background: soft(t.tint, 12), color: t.tint }}>
                            🔓 First {data.access.free_preview} lesson{data.access.free_preview === 1 ? '' : 's'} free
                        </span>
                    )}
                </div>
                <div className="mt-6 space-y-3">
                    {outline.map((n) => (
                        <Section key={n.id} node={n} tint={t.tint} />
                    ))}
                </div>
                <Link
                    href={startHref}
                    className="mt-8 inline-block rounded-full px-7 py-3 text-sm font-semibold"
                    style={{ background: t.tint, color: onTint(t.tint) }}
                >
                    Start learning →
                </Link>
            </section>
        </div>
    );
}

/** A top-level unit rendered as a card; its descendants listed inside. */
function Section({ node, tint }: { node: OutlineNode; tint: string }) {
    const hasChildren = node.children.length > 0;
    if (!hasChildren) return <LessonRow node={node} tint={tint} />;

    return (
        <div className="overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--card)]">
            <div className="flex items-center gap-3 border-b border-[var(--line)] px-5 py-3.5">
                {node.number && (
                    <span className="grid h-7 min-w-7 place-items-center rounded-full px-2 font-mono text-xs font-semibold" style={{ background: tint, color: onTint(tint) }}>
                        {node.number}
                    </span>
                )}
                <h3 className="font-display text-lg font-semibold">{node.title}</h3>
            </div>
            <div className="divide-y divide-[var(--line)]">
                {node.children.map((c) => (c.children.length ? <NestedGroup key={c.id} node={c} tint={tint} /> : <LessonRow key={c.id} node={c} tint={tint} bare />))}
            </div>
        </div>
    );
}

function NestedGroup({ node, tint }: { node: OutlineNode; tint: string }) {
    return (
        <div className="px-5 py-2">
            <p className="py-1.5 text-sm font-semibold text-[var(--ink)]">{node.number ? `${node.number}. ` : ''}{node.title}</p>
            <div className="pl-3">
                {node.children.map((c) => (
                    <LessonRow key={c.id} node={c} tint={tint} bare />
                ))}
            </div>
        </div>
    );
}

/** The ids of the openable lessons in an outline (matches the Learn definition). */
function outlineLessonIds(nodes: OutlineNode[]): string[] {
    const ids: string[] = [];
    const walk = (ns: OutlineNode[]) => {
        for (const n of ns) {
            const hasGrandchildren = n.children.some((c) => c.children.length > 0);
            const childHasContent = n.children.some((c) => c.has_content);
            if (!hasGrandchildren && (n.has_content || childHasContent)) ids.push(n.id);
            else walk(n.children);
        }
    };
    walk(nodes);
    return ids;
}

function readDone(slug: string): Set<string> {
    try {
        const raw = localStorage.getItem(`portal:progress:${slug}`);
        return new Set(raw ? (JSON.parse(raw) as string[]) : []);
    } catch {
        return new Set();
    }
}

function LessonRow({ node, tint, bare }: { node: OutlineNode; tint: string; bare?: boolean }) {
    return (
        <div className={bare ? 'flex items-center gap-3 py-2' : 'flex items-center gap-3 rounded-2xl border border-[var(--line)] bg-[var(--card)] px-5 py-3'}>
            {node.locked ? (
                <span className="shrink-0 text-xs" aria-label="Locked">🔒</span>
            ) : (
                <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: node.has_content ? tint : 'var(--line)' }} />
            )}
            <span className="text-sm text-[var(--muted)]">{node.title}</span>
        </div>
    );
}
