import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type Finding = {
    code: string;
    severity: 'error' | 'warning';
    message: string;
    anchor: { type: string; id: string | null };
};

type Publication = {
    number: number;
    published_by: string | null;
    published_at: string;
    changelog: string | null;
    is_current: boolean;
};

type Props = {
    course: { id: string; title: string; workflow_state: string };
    findings: Finding[];
    error_count: number;
    open_review: { id: string; submitted_by: string | null; assigned_to: string | null; note: string | null } | null;
    reviewers: { id: string; name: string }[];
    publications: Publication[];
    can: { submit: boolean; review: boolean; publish: boolean };
};

const STATE_STYLES: Record<string, string> = {
    draft: 'border-zinc-300 text-zinc-700 dark:border-zinc-700 dark:text-zinc-300',
    in_review: 'border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-200',
    changes_requested: 'border-red-300 text-red-800 dark:border-red-800 dark:text-red-200',
    approved: 'border-sky-300 text-sky-800 dark:border-sky-800 dark:text-sky-200',
    published: 'border-emerald-300 text-emerald-800 dark:border-emerald-800 dark:text-emerald-200',
};

export default function CourseReview({
    course,
    findings,
    error_count,
    open_review,
    reviewers,
    publications,
    can,
}: Props) {
    const state = course.workflow_state;
    const canSubmit = can.submit && (state === 'draft' || state === 'changes_requested');
    const canDecide = can.review && state === 'in_review';
    const canWithdraw = can.submit && state === 'in_review';
    const publishReady = can.publish && error_count === 0;

    return (
        <StudioLayout title={course.title}>
            <Head title={`Review · ${course.title}`} />

            <div className="mb-6 flex items-center gap-3 text-sm">
                <Link href={`/studio/courses/${course.id}`} className="text-zinc-600 hover:underline dark:text-zinc-400">
                    ← Back to editor
                </Link>
                <span
                    className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${STATE_STYLES[state] ?? STATE_STYLES.draft}`}
                >
                    {state.replace('_', ' ')}
                </span>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Readiness findings={findings} errorCount={error_count} />

                    {open_review && (
                        <Card title="Open review">
                            <dl className="space-y-1 text-sm">
                                <Row label="Submitted by" value={open_review.submitted_by} />
                                <Row label="Reviewer" value={open_review.assigned_to ?? 'Unassigned'} />
                                {open_review.note && <Row label="Note" value={open_review.note} />}
                            </dl>
                        </Card>
                    )}

                    {publications.length > 0 && (
                        <Card title="Publication history">
                            <ul className="space-y-2 text-sm">
                                {publications.map((p) => (
                                    <li key={p.number} className="flex items-baseline gap-2">
                                        <span className="font-medium">v{p.number}</span>
                                        {p.is_current && (
                                            <span className="rounded-full border border-emerald-300 px-1.5 text-xs text-emerald-700 dark:border-emerald-800 dark:text-emerald-300">
                                                current
                                            </span>
                                        )}
                                        <span className="text-zinc-500 dark:text-zinc-400">
                                            {p.changelog ?? 'No changelog'} — {p.published_by ?? 'unknown'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    {canSubmit && <SubmitForm courseId={course.id} reviewers={reviewers} />}
                    {canWithdraw && <WithdrawForm courseId={course.id} />}
                    {canDecide && <DecideForms courseId={course.id} />}
                    {(state === 'approved' || can.publish) && (
                        <PublishForm courseId={course.id} ready={publishReady} errorCount={error_count} canPublish={can.publish} />
                    )}
                </div>
            </div>
        </StudioLayout>
    );
}

function Readiness({ findings, errorCount }: { findings: Finding[]; errorCount: number }) {
    return (
        <Card title="Readiness">
            {findings.length === 0 ? (
                <p className="text-sm text-emerald-700 dark:text-emerald-300">
                    ✓ No problems. This course is ready to publish.
                </p>
            ) : (
                <>
                    <p className="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
                        {errorCount > 0
                            ? `${errorCount} error${errorCount === 1 ? '' : 's'} must be fixed before publishing.`
                            : 'No blocking errors — the notes below are advisory.'}
                    </p>
                    <ul className="space-y-2">
                        {findings.map((f, i) => (
                            <li key={i} className="flex gap-2 text-sm">
                                <span
                                    className={
                                        f.severity === 'error'
                                            ? 'font-semibold text-red-600'
                                            : 'font-semibold text-amber-600'
                                    }
                                >
                                    {f.severity === 'error' ? 'Error' : 'Warning'}
                                </span>
                                <span className="text-zinc-700 dark:text-zinc-300">{f.message}</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Card>
    );
}

function SubmitForm({ courseId, reviewers }: { courseId: string; reviewers: { id: string; name: string }[] }) {
    const { data, setData, post, processing, errors } = useForm({ assigned_to: '', note: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        post(`/studio/courses/${courseId}/submit`, { preserveScroll: true });
    }

    return (
        <Card title="Submit for review">
            <form onSubmit={submit} className="space-y-3">
                <div className="space-y-1.5">
                    <label className="block text-sm font-medium" htmlFor="assigned_to">
                        Assign a reviewer
                    </label>
                    <select
                        id="assigned_to"
                        value={data.assigned_to}
                        onChange={(e) => setData('assigned_to', e.target.value)}
                        className="w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <option value="">Unassigned</option>
                        {reviewers.map((r) => (
                            <option key={r.id} value={r.id}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                    {reviewers.length === 0 && (
                        <p className="text-xs text-amber-600">
                            No eligible reviewers exist yet. A reviewer cannot also be an author of this course.
                        </p>
                    )}
                    {errors.assigned_to && <p className="text-sm text-red-600">{errors.assigned_to}</p>}
                </div>
                <NoteField value={data.note} onChange={(v) => setData('note', v)} placeholder="Note for the reviewer (optional)" />
                <SubmitButton processing={processing}>Submit for review</SubmitButton>
            </form>
        </Card>
    );
}

function WithdrawForm({ courseId }: { courseId: string }) {
    const { post, processing } = useForm({});
    return (
        <Card title="Withdraw">
            <p className="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
                Pull this course back to draft and cancel the open review.
            </p>
            <button
                type="button"
                disabled={processing}
                onClick={() => post(`/studio/courses/${courseId}/withdraw`, { preserveScroll: true })}
                className="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
            >
                Withdraw review
            </button>
        </Card>
    );
}

function DecideForms({ courseId }: { courseId: string }) {
    const approve = useForm({ note: '' });
    const changes = useForm({ note: '' });

    return (
        <Card title="Your decision">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    approve.post(`/studio/courses/${courseId}/approve`, { preserveScroll: true });
                }}
                className="mb-4 space-y-3 border-b border-zinc-200 pb-4 dark:border-zinc-800"
            >
                <NoteField value={approve.data.note} onChange={(v) => approve.setData('note', v)} placeholder="Note (optional)" />
                <button
                    type="submit"
                    disabled={approve.processing}
                    className="w-full rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50"
                >
                    Approve
                </button>
            </form>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    changes.post(`/studio/courses/${courseId}/request-changes`, { preserveScroll: true });
                }}
                className="space-y-3"
            >
                <NoteField
                    value={changes.data.note}
                    onChange={(v) => changes.setData('note', v)}
                    placeholder="What needs to change? (required)"
                />
                {changes.errors.note && <p className="text-sm text-red-600">{changes.errors.note}</p>}
                <button
                    type="submit"
                    disabled={changes.processing}
                    className="w-full rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950"
                >
                    Request changes
                </button>
            </form>
        </Card>
    );
}

function PublishForm({
    courseId,
    ready,
    errorCount,
    canPublish,
}: {
    courseId: string;
    ready: boolean;
    errorCount: number;
    canPublish: boolean;
}) {
    const { data, setData, post, processing } = useForm({ changelog: '' });

    return (
        <Card title="Publish">
            {!canPublish ? (
                <p className="text-sm text-zinc-600 dark:text-zinc-400">
                    You do not have permission to publish this course.
                </p>
            ) : (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(`/studio/courses/${courseId}/publish`, { preserveScroll: true });
                    }}
                    className="space-y-3"
                >
                    <NoteField
                        value={data.changelog}
                        onChange={(v) => setData('changelog', v)}
                        placeholder="Changelog (optional)"
                    />
                    {errorCount > 0 && (
                        <p className="text-sm text-red-600">
                            Fix the {errorCount} error{errorCount === 1 ? '' : 's'} above first.
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={processing || !ready}
                        className="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Publish an immutable snapshot
                    </button>
                </form>
            )}
        </Card>
    );
}

/* ------------------------------------------------------------------------- */

function Card({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {title}
            </h2>
            {children}
        </section>
    );
}

function Row({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex gap-2">
            <dt className="w-24 shrink-0 text-zinc-500 dark:text-zinc-400">{label}</dt>
            <dd>{value ?? '—'}</dd>
        </div>
    );
}

function NoteField({
    value,
    onChange,
    placeholder,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
}) {
    return (
        <textarea
            rows={2}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={placeholder}
            className="w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
        />
    );
}

function SubmitButton({ processing, children }: { processing: boolean; children: React.ReactNode }) {
    return (
        <button
            type="submit"
            disabled={processing}
            className="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
        >
            {children}
        </button>
    );
}
