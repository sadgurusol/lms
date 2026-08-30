import type { CourseCardData } from '../api';
import { onTint, soft, subjectTheme } from '../lib/subject';
import { Link } from '../router';

export function CourseCard({ course }: { course: CourseCardData }) {
    const theme = subjectTheme(course.subject);
    const initial = (course.subject ?? course.title).trim().charAt(0).toUpperCase();

    return (
        <Link
            href={`/courses/${course.slug}`}
            className="group relative flex flex-col overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--card)] transition-all duration-200 hover:-translate-y-1 hover:shadow-[0_16px_50px_-16px_rgba(0,0,0,0.22)]"
            style={{ ['--tint' as string]: theme.tint }}
        >
            {/* Tinted crown with a large ghosted subject initial */}
            <div className="relative h-24 overflow-hidden" style={{ background: soft(theme.tint, 14) }}>
                <span
                    className="absolute -right-3 -top-6 select-none font-display text-[7rem] font-semibold leading-none opacity-20"
                    style={{ color: theme.tint }}
                >
                    {initial}
                </span>
                <span
                    className="absolute bottom-3 left-5 rounded-full px-2.5 py-1 text-xs font-semibold"
                    style={{ background: theme.tint, color: onTint(theme.tint) }}
                >
                    {course.subject ?? 'Course'}
                </span>
            </div>

            <div className="flex flex-1 flex-col p-5">
                <h3 className="font-display text-xl font-semibold leading-snug text-balance">{course.title}</h3>
                <p className="mt-1.5 flex items-center gap-2 text-sm text-[var(--muted)] tabular-nums">
                    {course.lessons ? <span>{course.lessons} lesson{course.lessons === 1 ? '' : 's'}</span> : null}
                    {course.lessons && course.grade_band ? <span aria-hidden>·</span> : null}
                    {course.grade_band && <span>{course.grade_band}</span>}
                </p>
                <span
                    className="mt-auto flex items-center gap-1.5 pt-5 text-sm font-semibold"
                    style={{ color: theme.tint }}
                >
                    Start learning
                    <span className="inline-block transition-transform group-hover:translate-x-1">→</span>
                </span>
            </div>
        </Link>
    );
}
