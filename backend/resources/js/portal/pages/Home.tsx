import { getCatalog } from '../api';
import { CourseCard } from '../components/CourseCard';
import { useAsync } from '../lib/useAsync';
import { soft, subjectTheme } from '../lib/subject';
import { usePageTitle } from '../lib/usePageTitle';
import { Link } from '../router';

export default function Home() {
    usePageTitle();
    const { loading, data } = useAsync(getCatalog, []);
    const courses = data?.data ?? [];
    const featured = courses.slice(0, 6);
    const subjects = data?.subjects ?? [];

    return (
        <div>
            <section className="portal-hero relative overflow-hidden border-b border-[var(--line)]">
                <div className="mx-auto max-w-6xl px-5 py-24 md:py-32">
                    <span className="inline-flex items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--card)] px-3 py-1 text-xs font-semibold text-[var(--accent)]">
                        <span className="h-1.5 w-1.5 rounded-full bg-[var(--accent)]" />
                        Free · Open · No sign-up
                    </span>
                    <h1 className="mt-6 max-w-3xl font-display text-5xl font-semibold leading-[1.03] tracking-tight text-balance md:text-[4.25rem]">
                        Lessons that <em className="not-italic" style={{ color: 'var(--accent)' }}>explain</em>, not just tell.
                    </h1>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed text-[var(--muted)] text-pretty">
                        Animated, narrated lessons across every subject. Open one and start learning — each idea reveals a beat at a time, with a diagram and a voice to guide you.
                    </p>
                    <div className="mt-9 flex flex-wrap items-center gap-4">
                        <Link href="/courses" className="rounded-full bg-[var(--accent)] px-6 py-3 text-sm font-semibold text-[var(--on-accent)] shadow-sm transition hover:opacity-90">
                            Browse courses
                        </Link>
                        {!loading && (
                            <span className="text-sm text-[var(--muted)]">
                                <b className="font-semibold text-[var(--ink)] tabular-nums">{courses.length}</b> course{courses.length === 1 ? '' : 's'}
                                {subjects.length > 0 && (
                                    <>
                                        {' '}across <b className="font-semibold text-[var(--ink)] tabular-nums">{subjects.length}</b> subject{subjects.length === 1 ? '' : 's'}
                                    </>
                                )}
                            </span>
                        )}
                    </div>
                </div>
            </section>

            <section className="border-b border-[var(--line)]">
                <div className="mx-auto grid max-w-6xl gap-8 px-5 py-14 sm:grid-cols-3">
                    {[
                        { n: '1', t: 'Pick a course', d: 'Browse by subject and open any lesson. No account, nothing to buy.' },
                        { n: '2', t: 'Watch it explain', d: 'Each idea reveals a beat at a time — with a diagram and a voice to guide you.' },
                        { n: '3', t: 'Learn at your pace', d: 'Pause, replay, and pick up where you left off. Your progress stays on this device.' },
                    ].map((s) => (
                        <div key={s.n}>
                            <span className="grid h-9 w-9 place-items-center rounded-full bg-[var(--accent-soft)] font-display text-lg font-semibold text-[var(--accent)]">
                                {s.n}
                            </span>
                            <h3 className="mt-4 font-display text-lg font-semibold">{s.t}</h3>
                            <p className="mt-1.5 text-sm leading-relaxed text-[var(--muted)] text-pretty">{s.d}</p>
                        </div>
                    ))}
                </div>
            </section>

            <section className="mx-auto max-w-6xl px-5 py-16">
                <SectionHead title="Featured courses" href="/courses" cta="See all" />
                {loading ? (
                    <CardsSkeleton />
                ) : featured.length ? (
                    <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {featured.map((c) => (
                            <CourseCard key={c.slug} course={c} />
                        ))}
                    </div>
                ) : (
                    <p className="mt-8 text-[var(--muted)]">No courses published yet — check back soon.</p>
                )}
            </section>

            {subjects.length > 0 && (
                <section className="mx-auto max-w-6xl px-5 pb-8">
                    <SectionHead title="Browse by subject" />
                    <div className="mt-6 flex flex-wrap gap-3">
                        {subjects.map((s) => {
                            const t = subjectTheme(s);
                            return (
                                <Link
                                    key={s}
                                    href={`/courses?subject=${encodeURIComponent(s)}`}
                                    className="rounded-full border px-4 py-2 text-sm font-semibold transition hover:-translate-y-0.5"
                                    style={{ color: t.tint, borderColor: soft(t.tint, 35), background: soft(t.tint, 8) }}
                                >
                                    {s}
                                </Link>
                            );
                        })}
                    </div>
                </section>
            )}
        </div>
    );
}

function SectionHead({ title, href, cta }: { title: string; href?: string; cta?: string }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-3">
                <span className="h-5 w-1 rounded-full bg-[var(--accent)]" />
                <h2 className="font-display text-3xl font-semibold">{title}</h2>
            </div>
            {href && cta && (
                <Link href={href} className="shrink-0 text-sm font-semibold text-[var(--accent)]">
                    {cta} →
                </Link>
            )}
        </div>
    );
}

function CardsSkeleton() {
    return (
        <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="h-56 animate-pulse rounded-2xl border border-[var(--line)] bg-[var(--card)]" />
            ))}
        </div>
    );
}
