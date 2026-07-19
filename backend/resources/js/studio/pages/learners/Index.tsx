import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';

type Learner = {
    id: string;
    name: string;
    email: string;
    status: 'invited' | 'active' | 'suspended';
    joined: string | null;
};

type Props = { learners: Learner[]; search: string };

const STATUS_STYLE: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    suspended: 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    invited: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
};

export default function LearnersIndex({ learners, search }: Props) {
    const [query, setQuery] = useState(search);

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get('/studio/learners', { search: query }, { preserveState: true, replace: true });
    }

    return (
        <StudioLayout title="Learners">
            <Head title="Learners" />

            <p className="mb-4 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                People who signed up directly through the app. Suspend to revoke access, or open one to
                comp them into a product. (Learners launched by a school are managed under their client.)
            </p>

            <form onSubmit={submit} className="mb-4 flex gap-2">
                <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search by name or email"
                    className="w-full max-w-sm rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                <button type="submit" className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Search
                </button>
            </form>

            {learners.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No learners found.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table className="w-full text-sm">
                        <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium">Joined</th>
                                <th className="px-4 py-2 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                            {learners.map((learner) => (
                                <LearnerRow key={learner.id} learner={learner} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </StudioLayout>
    );
}

function LearnerRow({ learner }: { learner: Learner }) {
    const confirm = useConfirm();

    async function toggle() {
        const next = learner.status === 'suspended' ? 'active' : 'suspended';
        if (next === 'suspended') {
            const ok = await confirm({
                title: 'Suspend learner',
                message: (
                    <>
                        Suspend <strong>{learner.name}</strong>? They lose access immediately.
                    </>
                ),
                confirmLabel: 'Suspend',
                danger: true,
            });
            if (!ok) return;
        }
        router.patch(`/studio/learners/${learner.id}/status`, { status: next }, { preserveScroll: true });
    }

    return (
        <tr>
            <td className="px-4 py-3">
                <p className="font-medium">{learner.name}</p>
                <p className="text-sm text-zinc-500 dark:text-zinc-400">{learner.email}</p>
            </td>
            <td className="px-4 py-3">
                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLE[learner.status]}`}>
                    {learner.status}
                </span>
            </td>
            <td className="px-4 py-3 text-zinc-500 dark:text-zinc-400">{learner.joined ?? '—'}</td>
            <td className="px-4 py-3">
                <div className="flex items-center justify-end gap-3">
                    <Link href={`/studio/learners/${learner.id}`} className="text-sm text-indigo-600 hover:underline">
                        Manage
                    </Link>
                    <button
                        type="button"
                        onClick={() => void toggle()}
                        className={
                            learner.status === 'suspended'
                                ? 'text-sm text-emerald-600 hover:underline'
                                : 'text-sm text-red-600 hover:underline'
                        }
                    >
                        {learner.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                    </button>
                </div>
            </td>
        </tr>
    );
}
