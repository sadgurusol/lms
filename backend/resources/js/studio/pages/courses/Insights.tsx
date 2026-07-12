import { Head, Link } from '@inertiajs/react';
import StudioLayout from '@/studio/components/StudioLayout';

type Summary = {
    learners: number;
    completed_course: number;
    average_completion: number;
    at_risk: number;
    median_minutes: number;
    total_minutes: number;
    quizzes_graded: number;
    quiz_average: number | null;
    pass_rate: number | null;
};

type Bucket = { label: string; count: number };

type AssessmentRow = {
    id: string;
    title: string;
    kind: string;
    attempts: number;
    average: number | null;
    pass_rate: number | null;
};

type LearnerRow = {
    ref: string;
    kind: 'b2b' | 'direct';
    completion_percent: number;
    completed_nodes: number;
    total_nodes: number;
    seconds_spent: number;
    quizzes_taken: number;
    quiz_average: number | null;
    at_risk: boolean;
};

type Props = {
    course: { id: string; title: string };
    published: boolean;
    summary: Summary;
    score_distribution: Bucket[];
    assessments: AssessmentRow[];
    learners: LearnerRow[];
};

const pct = (v: number | null) => (v === null ? '—' : `${Math.round(v)}%`);

function minutes(total: number): string {
    if (total < 60) return `${total}m`;
    const h = Math.floor(total / 60);
    const m = total % 60;
    return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

export default function CourseInsights({
    course,
    published,
    summary,
    score_distribution,
    assessments,
    learners,
}: Props) {
    return (
        <StudioLayout title={`${course.title} · insights`}>
            <Head title={`${course.title} · insights`} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href={`/studio/courses/${course.id}`} className="hover:underline">
                    ← Back to editor
                </Link>
            </div>

            <h1 className="mb-1 text-xl font-semibold">Insights</h1>
            <p className="mb-6 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                How this course is performing across everyone learning it. Figures are aggregate;
                individual learners are de-identified — schools receive their own students' activity
                directly.
            </p>

            {!published ? (
                <EmptyState note="This course has not been published yet, so there is nothing to measure." />
            ) : summary.learners === 0 ? (
                <EmptyState note="No one has started this course yet. Check back once learners are active." />
            ) : (
                <div className="space-y-8">
                    <section className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <Stat label="Learners" value={String(summary.learners)} />
                        <Stat label="Avg completion" value={`${Math.round(summary.average_completion)}%`} />
                        <Stat
                            label="Finished course"
                            value={String(summary.completed_course)}
                            sub={`of ${summary.learners}`}
                        />
                        <Stat
                            label="At risk"
                            value={String(summary.at_risk)}
                            tone={summary.at_risk > 0 ? 'warn' : undefined}
                            sub="quiz avg < 50%"
                        />
                        <Stat label="Median time" value={minutes(summary.median_minutes)} sub="per learner" />
                        <Stat label="Quiz average" value={pct(summary.quiz_average)} />
                        <Stat label="Pass rate" value={pct(summary.pass_rate)} />
                        <Stat
                            label="Quizzes graded"
                            value={String(summary.quizzes_graded)}
                            sub={`${minutes(summary.total_minutes)} total`}
                        />
                    </section>

                    <section>
                        <h2 className="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                            Quiz score distribution
                        </h2>
                        {summary.quizzes_graded === 0 ? (
                            <p className="text-sm text-zinc-500 dark:text-zinc-400">No graded quizzes yet.</p>
                        ) : (
                            <Histogram buckets={score_distribution} />
                        )}
                    </section>

                    <section>
                        <h2 className="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                            By assessment
                        </h2>
                        {assessments.length === 0 ? (
                            <p className="text-sm text-zinc-500 dark:text-zinc-400">
                                This course has no quizzes or tests.
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                                <table className="w-full text-sm">
                                    <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                                        <tr>
                                            <th className="px-4 py-2 font-medium">Assessment</th>
                                            <th className="px-4 py-2 font-medium">Type</th>
                                            <th className="px-4 py-2 text-right font-medium">Attempts</th>
                                            <th className="px-4 py-2 text-right font-medium">Average</th>
                                            <th className="px-4 py-2 text-right font-medium">Pass rate</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        {assessments.map((a) => (
                                            <tr key={a.id}>
                                                <td className="px-4 py-2 font-medium">{a.title}</td>
                                                <td className="px-4 py-2 capitalize text-zinc-500 dark:text-zinc-400">
                                                    {a.kind}
                                                </td>
                                                <td className="px-4 py-2 text-right tabular-nums">{a.attempts}</td>
                                                <td className="px-4 py-2 text-right tabular-nums">{pct(a.average)}</td>
                                                <td className="px-4 py-2 text-right tabular-nums">{pct(a.pass_rate)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section>
                        <h2 className="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                            Learners
                        </h2>
                        <div className="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                            <table className="w-full text-sm">
                                <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">Learner</th>
                                        <th className="px-4 py-2 font-medium">Source</th>
                                        <th className="px-4 py-2 font-medium">Completion</th>
                                        <th className="px-4 py-2 text-right font-medium">Time</th>
                                        <th className="px-4 py-2 text-right font-medium">Quizzes</th>
                                        <th className="px-4 py-2 text-right font-medium">Quiz avg</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    {learners.map((l) => (
                                        <tr
                                            key={l.ref}
                                            className={l.at_risk ? 'bg-amber-50/60 dark:bg-amber-950/20' : undefined}
                                        >
                                            <td className="px-4 py-2 font-mono text-xs">
                                                {l.ref}
                                                {l.at_risk && (
                                                    <span className="ml-2 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                                        at risk
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-zinc-500 dark:text-zinc-400">
                                                {l.kind === 'b2b' ? 'School' : 'Direct'}
                                            </td>
                                            <td className="px-4 py-2">
                                                <ProgressBar
                                                    percent={l.completion_percent}
                                                    label={`${l.completed_nodes}/${l.total_nodes}`}
                                                />
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {minutes(Math.round(l.seconds_spent / 60))}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">{l.quizzes_taken}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{pct(l.quiz_average)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            )}
        </StudioLayout>
    );
}

function Stat({
    label,
    value,
    sub,
    tone,
}: {
    label: string;
    value: string;
    sub?: string;
    tone?: 'warn';
}) {
    return (
        <div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
            <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {label}
            </p>
            <p
                className={`mt-1 text-2xl font-semibold tabular-nums ${
                    tone === 'warn' ? 'text-amber-600 dark:text-amber-400' : ''
                }`}
            >
                {value}
            </p>
            {sub && <p className="text-xs text-zinc-400 dark:text-zinc-500">{sub}</p>}
        </div>
    );
}

function Histogram({ buckets }: { buckets: Bucket[] }) {
    const max = Math.max(1, ...buckets.map((b) => b.count));
    return (
        <div className="flex items-end gap-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
            {buckets.map((b) => (
                <div key={b.label} className="flex flex-1 flex-col items-center gap-1">
                    <span className="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{b.count}</span>
                    <div
                        className="w-full rounded-t bg-indigo-500/80 dark:bg-indigo-400/80"
                        style={{ height: `${Math.max(4, (b.count / max) * 120)}px` }}
                        title={`${b.count} in ${b.label}%`}
                    />
                    <span className="text-[10px] text-zinc-400 dark:text-zinc-500">{b.label}</span>
                </div>
            ))}
        </div>
    );
}

function ProgressBar({ percent, label }: { percent: number; label: string }) {
    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                <div
                    className="h-full rounded-full bg-indigo-500 dark:bg-indigo-400"
                    style={{ width: `${Math.min(100, percent)}%` }}
                />
            </div>
            <span className="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{label}</span>
        </div>
    );
}

function EmptyState({ note }: { note: string }) {
    return (
        <div className="rounded-lg border border-dashed border-zinc-300 p-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {note}
        </div>
    );
}
