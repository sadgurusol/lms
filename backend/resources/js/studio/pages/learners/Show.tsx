import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';

type Learner = { id: string; name: string; email: string; status: string; joined: string | null };
type Comp = { id: string; product: string; reason: string; starts_at: string; ends_at: string | null };
type Sub = { plan: string; status: string; entitles: boolean };
type Purchase = { product: string; status: string };
type ProductRef = { id: string; name: string };

type Props = {
    learner: Learner;
    courses: { id: string; title: string }[];
    comps: Comp[];
    subscriptions: Sub[];
    purchases: Purchase[];
    products: ProductRef[];
};

export default function LearnerShow({ learner, courses, comps, subscriptions, purchases, products }: Props) {
    const confirm = useConfirm();

    async function toggleStatus() {
        const next = learner.status === 'suspended' ? 'active' : 'suspended';
        if (next === 'suspended') {
            const ok = await confirm({
                title: 'Suspend learner',
                message: `Suspend ${learner.name}? They lose access immediately.`,
                confirmLabel: 'Suspend',
                danger: true,
            });
            if (!ok) return;
        }
        router.patch(`/studio/learners/${learner.id}/status`, { status: next }, { preserveScroll: true });
    }

    async function revoke(comp: Comp) {
        const ok = await confirm({
            title: 'Revoke access',
            message: `Revoke comp access to “${comp.product}”?`,
            confirmLabel: 'Revoke',
            danger: true,
        });
        if (ok) router.delete(`/studio/comps/${comp.id}`, { preserveScroll: true });
    }

    return (
        <StudioLayout title={learner.name}>
            <Head title={learner.name} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href="/studio/learners" className="hover:underline">
                    ← Learners
                </Link>
            </div>

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <div className="flex-1">
                    <h1 className="text-xl font-semibold">{learner.name}</h1>
                    <p className="text-sm text-zinc-500 dark:text-zinc-400">
                        {learner.email} · joined {learner.joined ?? '—'} ·{' '}
                        <span className="capitalize">{learner.status}</span>
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => void toggleStatus()}
                    className={`rounded-md border px-3 py-1.5 text-sm font-medium ${
                        learner.status === 'suspended'
                            ? 'border-emerald-300 text-emerald-700 dark:border-emerald-800 dark:text-emerald-300'
                            : 'border-red-300 text-red-700 dark:border-red-800 dark:text-red-300'
                    }`}
                >
                    {learner.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                </button>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Access">
                    {courses.length === 0 ? (
                        <p className="text-sm text-zinc-500 dark:text-zinc-400">No courses yet.</p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {courses.map((c) => (
                                <li key={c.id} className="flex items-center gap-2">
                                    <span className="text-emerald-600">✓</span>
                                    {c.title}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Comp access">
                    <GrantForm learnerId={learner.id} products={products} />
                    {comps.length === 0 ? (
                        <p className="mt-3 text-sm text-zinc-500 dark:text-zinc-400">No comp grants.</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">
                            {comps.map((comp) => (
                                <li key={comp.id} className="flex items-center gap-2 py-2 text-sm">
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium">{comp.product}</span>
                                        <span className="text-zinc-500 dark:text-zinc-400">
                                            {' '}· {comp.reason}
                                            {comp.ends_at ? ` · until ${comp.ends_at}` : ''}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => void revoke(comp)}
                                        className="text-red-600 hover:underline"
                                    >
                                        Revoke
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Subscriptions">
                    {subscriptions.length === 0 ? (
                        <p className="text-sm text-zinc-500 dark:text-zinc-400">None.</p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {subscriptions.map((s, i) => (
                                <li key={i} className="flex items-center justify-between">
                                    <span>{s.plan}</span>
                                    <span className={s.entitles ? 'text-emerald-600' : 'text-zinc-500'}>{s.status}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Purchases">
                    {purchases.length === 0 ? (
                        <p className="text-sm text-zinc-500 dark:text-zinc-400">None.</p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {purchases.map((p, i) => (
                                <li key={i} className="flex items-center justify-between">
                                    <span>{p.product}</span>
                                    <span className="text-zinc-500">{p.status}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </StudioLayout>
    );
}

function GrantForm({ learnerId, products }: { learnerId: string; products: ProductRef[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        product_id: products[0]?.id ?? '',
        ends_at: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post(`/studio/learners/${learnerId}/comps`, { preserveScroll: true, onSuccess: () => reset('ends_at') });
    }

    if (products.length === 0) {
        return <p className="text-sm text-amber-600">No active products to grant.</p>;
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
            <div className="min-w-0 flex-1 space-y-1">
                <label className="block text-xs font-medium text-zinc-500">Grant a product</label>
                <select
                    value={data.product_id}
                    onChange={(e) => setData('product_id', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    {products.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
                {errors.product_id && <p className="text-xs text-red-600">{errors.product_id}</p>}
            </div>
            <div className="space-y-1">
                <label className="block text-xs font-medium text-zinc-500">Until (optional)</label>
                <input
                    type="date"
                    value={data.ends_at}
                    onChange={(e) => setData('ends_at', e.target.value)}
                    className="rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                {errors.ends_at && <p className="text-xs text-red-600">{errors.ends_at}</p>}
            </div>
            <button
                type="submit"
                disabled={processing}
                className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                Grant
            </button>
        </form>
    );
}

function Card({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
            <h2 className="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{title}</h2>
            {children}
        </div>
    );
}
