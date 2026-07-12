import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';

type Member = {
    id: string;
    role: string;
    user: { id: string; name: string; email: string | null };
};

type StaffUser = { id: string; name: string; email: string | null };

type Props = {
    course: { id: string; title: string };
    grants: Member[];
    assignable: StaffUser[];
    roles: string[];
    can: { manage: boolean };
};

const ROLE_STYLES: Record<string, string> = {
    owner: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    author: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    reviewer: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
};

const ROLE_HELP: Record<string, string> = {
    owner: 'Full control, including managing this team and publishing.',
    author: 'Edits the course content and submits it for review.',
    reviewer: 'Reviews and approves — and, for that reason, may not also edit.',
};

export default function CourseTeam({ course, grants, assignable, roles, can }: Props) {
    const [adding, setAdding] = useState(false);
    const confirm = useConfirm();

    async function removeGrant(grant: Member) {
        const ok = await confirm({
            title: 'Remove team member',
            message: (
                <>
                    Remove <strong>{grant.user.name}</strong> as {grant.role}?
                </>
            ),
            confirmLabel: 'Remove',
            danger: true,
        });
        if (ok) router.delete(`/studio/course-grants/${grant.id}`, { preserveScroll: true });
    }

    return (
        <StudioLayout title={`${course.title} · team`}>
            <Head title={`${course.title} · team`} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href={`/studio/courses/${course.id}`} className="hover:underline">
                    ← Back to editor
                </Link>
            </div>

            <p className="mb-6 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                Who works on this course. Owners and authors edit it; reviewers approve it. A person
                cannot both edit and review the same course — that separation is what makes an approval
                mean something.
            </p>

            {can.manage && (adding ? (
                <AddForm course={course} assignable={assignable} roles={roles} onDone={() => setAdding(false)} />
            ) : (
                <button
                    type="button"
                    onClick={() => setAdding(true)}
                    className="mb-6 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    Add team member
                </button>
            ))}

            {grants.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No one is assigned yet.</p>
            ) : (
                <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    {grants.map((g) => (
                        <li key={g.id} className="flex items-center gap-3 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{g.user.name}</p>
                                {g.user.email && (
                                    <p className="truncate text-sm text-zinc-500 dark:text-zinc-400">{g.user.email}</p>
                                )}
                            </div>
                            <span
                                className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                    ROLE_STYLES[g.role] ?? ROLE_STYLES.author
                                }`}
                            >
                                {g.role}
                            </span>
                            {can.manage && (
                                <button
                                    type="button"
                                    onClick={() => void removeGrant(g)}
                                    className="rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                                >
                                    Remove
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </StudioLayout>
    );
}

function AddForm({
    course,
    assignable,
    roles,
    onDone,
}: {
    course: { id: string };
    assignable: StaffUser[];
    roles: string[];
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: assignable[0]?.id ?? '',
        role: 'author',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post(`/studio/courses/${course.id}/grants`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    }

    return (
        <form
            onSubmit={submit}
            className="mb-6 max-w-xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="grant-user">
                    Person
                </label>
                {assignable.length === 0 ? (
                    <p className="text-sm text-amber-600">
                        No eligible staff. Invite authors or reviewers first.
                    </p>
                ) : (
                    <select
                        id="grant-user"
                        value={data.user_id}
                        onChange={(e) => setData('user_id', e.target.value)}
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        {assignable.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                                {u.email ? ` (${u.email})` : ''}
                            </option>
                        ))}
                    </select>
                )}
                {errors.user_id && <p className="text-sm text-red-600">{errors.user_id}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="grant-role">
                    Role
                </label>
                <select
                    id="grant-role"
                    value={data.role}
                    onChange={(e) => setData('role', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    {roles.map((r) => (
                        <option key={r} value={r}>
                            {r}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-zinc-500 dark:text-zinc-400">{ROLE_HELP[data.role]}</p>
                {errors.role && <p className="text-sm text-red-600">{errors.role}</p>}
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing || assignable.length === 0}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Add
                </button>
                <button type="button" onClick={onDone} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}
