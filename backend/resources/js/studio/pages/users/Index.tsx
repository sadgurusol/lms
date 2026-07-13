import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';

type StaffMember = {
    id: string;
    name: string;
    email: string;
    status: 'invited' | 'active' | 'suspended';
    roles: string[];
    is_self: boolean;
};

type Props = { staff: StaffMember[]; roles: string[] };

const ROLE_LABEL: Record<string, string> = {
    admin: 'Admin',
    ops: 'Ops',
    content_author: 'Content author',
    content_reviewer: 'Content reviewer',
    instructor: 'Instructor',
};

const STATUS_STYLE: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    invited: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    suspended: 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
};

export default function StaffIndex({ staff, roles }: Props) {
    const [inviting, setInviting] = useState(false);

    return (
        <StudioLayout title="Staff">
            <Head title="Staff" />

            <div className="mb-6 flex items-center justify-between">
                <p className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    The people who work in the studio. Invite someone and they'll get an email to set
                    their own password. Suspend to revoke access without losing their history.
                </p>
                {!inviting && (
                    <button
                        type="button"
                        onClick={() => setInviting(true)}
                        className="shrink-0 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                    >
                        Invite staff
                    </button>
                )}
            </div>

            {inviting && <InviteForm roles={roles} onDone={() => setInviting(false)} />}

            <div className="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                <table className="w-full text-sm">
                    <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th className="px-4 py-2 font-medium">Name</th>
                            <th className="px-4 py-2 font-medium">Role</th>
                            <th className="px-4 py-2 font-medium">Status</th>
                            <th className="px-4 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                        {staff.map((member) => (
                            <StaffRow key={member.id} member={member} roles={roles} />
                        ))}
                    </tbody>
                </table>
            </div>
        </StudioLayout>
    );
}

function StaffRow({ member, roles }: { member: StaffMember; roles: string[] }) {
    const confirm = useConfirm();

    function setRole(role: string) {
        if (role !== member.roles[0]) {
            router.patch(`/studio/users/${member.id}`, { role }, { preserveScroll: true });
        }
    }

    async function toggleStatus() {
        const next = member.status === 'suspended' ? 'active' : 'suspended';
        if (next === 'suspended') {
            const ok = await confirm({
                title: 'Suspend staff member',
                message: (
                    <>
                        Suspend <strong>{member.name}</strong>? They lose studio access immediately but keep
                        their history.
                    </>
                ),
                confirmLabel: 'Suspend',
                danger: true,
            });
            if (!ok) return;
        }
        router.patch(`/studio/users/${member.id}`, { status: next }, { preserveScroll: true });
    }

    function resend() {
        router.post(`/studio/users/${member.id}/invite`, {}, { preserveScroll: true });
    }

    return (
        <tr>
            <td className="px-4 py-3">
                <p className="font-medium">
                    {member.name}
                    {member.is_self && <span className="ml-2 text-xs text-zinc-400">(you)</span>}
                </p>
                <p className="text-sm text-zinc-500 dark:text-zinc-400">{member.email}</p>
            </td>
            <td className="px-4 py-3">
                <select
                    value={member.roles[0] ?? ''}
                    onChange={(e) => setRole(e.target.value)}
                    className="rounded-md border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    {roles.map((r) => (
                        <option key={r} value={r}>
                            {ROLE_LABEL[r] ?? r}
                        </option>
                    ))}
                </select>
            </td>
            <td className="px-4 py-3">
                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLE[member.status]}`}>
                    {member.status}
                </span>
            </td>
            <td className="px-4 py-3">
                <div className="flex items-center justify-end gap-3">
                    {member.status !== 'active' && (
                        <button type="button" onClick={resend} className="text-sm text-indigo-600 hover:underline">
                            Resend invite
                        </button>
                    )}
                    {!member.is_self && (
                        <button
                            type="button"
                            onClick={() => void toggleStatus()}
                            className={
                                member.status === 'suspended'
                                    ? 'text-sm text-emerald-600 hover:underline'
                                    : 'text-sm text-red-600 hover:underline'
                            }
                        >
                            {member.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                        </button>
                    )}
                </div>
            </td>
        </tr>
    );
}

function InviteForm({ roles, onDone }: { roles: string[]; onDone: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        role: 'content_author',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/users', {
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
            className="mb-6 grid max-w-3xl gap-4 rounded-lg border border-zinc-200 p-4 sm:grid-cols-3 dark:border-zinc-800"
        >
            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="staff-name">
                    Name
                </label>
                <input
                    id="staff-name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
            </div>
            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="staff-email">
                    Email
                </label>
                <input
                    id="staff-email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
            </div>
            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="staff-role">
                    Role
                </label>
                <select
                    id="staff-role"
                    value={data.role}
                    onChange={(e) => setData('role', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    {roles.map((r) => (
                        <option key={r} value={r}>
                            {ROLE_LABEL[r] ?? r}
                        </option>
                    ))}
                </select>
            </div>
            <div className="flex gap-2 sm:col-span-3">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Send invitation
                </button>
                <button type="button" onClick={onDone} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}
