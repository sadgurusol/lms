import { useAuthModal } from '../components/AuthModal';
import { useAuth } from '../lib/auth';
import { fetchMyCourses, type MyCourse } from '../lib/progress';
import { soft, subjectTheme } from '../lib/subject';
import { useAsync } from '../lib/useAsync';
import { usePageTitle } from '../lib/usePageTitle';
import { Link } from '../router';

export default function My() {
    usePageTitle('My learning');
    const { user, loading: authLoading } = useAuth();
    const { open } = useAuthModal();
    const { loading, data } = useAsync(() => (user ? fetchMyCourses() : Promise.resolve([])), [user]);

    if (authLoading) {
        return <div className="mx-auto max-w-6xl px-5 py-24 text-[var(--muted)]">Loading…</div>;
    }

    if (!user) {
        return (
            <div className="mx-auto max-w-lg px-5 py-24 text-center">
                <h1 className="font-display text-3xl font-semibold">Your learning, saved</h1>
                <p className="mt-3 text-[var(--muted)]">Sign in to keep your progress and pick up on any device.</p>
                <button onClick={() => open('signin')} className="mt-6 rounded-full bg-[var(--accent)] px-6 py-2.5 text-sm font-semibold text-[var(--on-accent)]">
                    Sign in
                </button>
            </div>
        );
    }

    const courses = data ?? [];

    return (
        <div className="mx-auto max-w-6xl px-5 py-12">
            <h1 className="font-display text-4xl font-semibold">My learning</h1>
            <p className="mt-2 text-[var(--muted)]">Courses you’ve started or added.</p>

            {loading ? (
                <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {Array.from({ length: 3 }).map((_, i) => (
                        <div key={i} className="h-40 animate-pulse rounded-2xl border border-[var(--line)] bg-[var(--card)]" />
                    ))}
                </div>
            ) : courses.length ? (
                <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {courses.map((c) => (
                        <MyCard key={c.slug} course={c} />
                    ))}
                </div>
            ) : (
                <div className="mt-10 rounded-2xl border border-dashed border-[var(--line)] p-10 text-center">
                    <p className="text-[var(--muted)]">You haven’t started any courses yet.</p>
                    <Link href="/courses" className="mt-4 inline-block rounded-full bg-[var(--accent)] px-5 py-2.5 text-sm font-semibold text-[var(--on-accent)]">
                        Browse courses
                    </Link>
                </div>
            )}
        </div>
    );
}

function MyCard({ course }: { course: MyCourse }) {
    const t = subjectTheme(course.subject);
    const pct = course.lessons ? Math.round((course.done / course.lessons) * 100) : 0;
    const complete = course.lessons > 0 && course.done >= course.lessons;

    return (
        <Link
            href={`/courses/${course.slug}/learn`}
            className="group flex flex-col rounded-2xl border border-[var(--line)] bg-[var(--card)] p-5 transition-all hover:-translate-y-1 hover:border-[var(--accent)]"
            style={{ ['--tint' as string]: t.tint }}
        >
            {course.subject && (
                <span className="w-fit rounded-full px-2.5 py-1 text-xs font-semibold" style={{ background: soft(t.tint, 12), color: t.tint }}>
                    {course.subject}
                </span>
            )}
            <h3 className="mt-3 font-display text-lg font-semibold leading-snug text-balance">{course.title}</h3>

            <div className="mt-auto pt-5">
                <div className="flex items-center justify-between text-xs font-semibold tabular-nums text-[var(--muted)]">
                    <span>{complete ? 'Completed 🎉' : `${course.done} / ${course.lessons} lessons`}</span>
                    <span style={{ color: t.tint }}>{pct}%</span>
                </div>
                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-[var(--line)]">
                    <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: t.tint }} />
                </div>
            </div>
        </Link>
    );
}
