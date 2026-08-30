import { useState } from 'react';
import { getCatalog } from '../api';
import { CourseCard } from '../components/CourseCard';
import { useAsync } from '../lib/useAsync';
import { usePageTitle } from '../lib/usePageTitle';
import { Link, useRouter } from '../router';

/** Build a /courses href from the current query with some params changed/removed. */
function hrefWith(current: URLSearchParams, changes: Record<string, string | null>): string {
    const p = new URLSearchParams(current);
    for (const [k, v] of Object.entries(changes)) {
        if (v === null) p.delete(k);
        else p.set(k, v);
    }
    const s = p.toString();
    return s ? `/courses?${s}` : '/courses';
}

export default function Catalog() {
    const { query } = useRouter();
    const { loading, data, error } = useAsync(getCatalog, []);
    const [q, setQ] = useState('');

    const category = query.get('category');
    const subject = query.get('subject');
    const categories = data?.categories ?? [];
    const categoryLabel = categories.find((c) => c.value === category)?.label;
    usePageTitle(categoryLabel ? `${categoryLabel} courses` : 'Courses');

    const courses = (data?.data ?? []).filter((c) => {
        if (category && c.category !== category) return false;
        if (subject && c.subject !== subject) return false;
        if (q.trim() && !`${c.title} ${c.subject ?? ''}`.toLowerCase().includes(q.trim().toLowerCase())) return false;
        return true;
    });

    // Subjects available within the current category (so filters stay coherent).
    const subjects = [
        ...new Set(
            (data?.data ?? [])
                .filter((c) => !category || c.category === category)
                .map((c) => c.subject)
                .filter((s): s is string => !!s),
        ),
    ].sort();

    return (
        <div className="mx-auto max-w-6xl px-5 py-12">
            <h1 className="font-display text-4xl font-semibold">{categoryLabel ? `${categoryLabel} courses` : 'Courses'}</h1>
            <p className="mt-2 text-[var(--muted)]">Open any lesson and start learning — free, no sign-up.</p>

            {/* Search + subject filter (category is chosen from the top-bar Courses menu) */}
            <div className="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center">
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search courses…"
                    className="w-full rounded-full border border-[var(--line)] bg-[var(--card)] px-5 py-2.5 text-sm outline-none focus:border-[var(--accent)] sm:max-w-xs"
                />
                {subjects.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        <Chip href={hrefWith(query, { subject: null })} active={!subject}>All subjects</Chip>
                        {subjects.map((s) => (
                            <Chip key={s} href={hrefWith(query, { subject: s })} active={subject === s}>
                                {s}
                            </Chip>
                        ))}
                    </div>
                )}
            </div>

            {error && <p className="mt-10 text-[var(--muted)]">Couldn’t load courses. Please try again.</p>}

            {loading ? (
                <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {Array.from({ length: 6 }).map((_, i) => (
                        <div key={i} className="h-56 animate-pulse rounded-2xl border border-[var(--line)] bg-[var(--card)]" />
                    ))}
                </div>
            ) : courses.length ? (
                <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {courses.map((c) => (
                        <CourseCard key={c.slug} course={c} />
                    ))}
                </div>
            ) : (
                <p className="mt-10 text-[var(--muted)]">No courses match your filters.</p>
            )}
        </div>
    );
}

function Chip({ href, active, children }: { href: string; active: boolean; children: string }) {
    return (
        <Link
            href={href}
            className={`rounded-full border px-4 py-2 text-sm font-medium transition ${
                active
                    ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--on-accent)]'
                    : 'border-[var(--line)] bg-[var(--card)] text-[var(--muted)] hover:border-[var(--accent)] hover:text-[var(--accent)]'
            }`}
        >
            {children}
        </Link>
    );
}
