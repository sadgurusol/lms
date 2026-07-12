import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type Product = { id: string; sku: string; name: string; kind: string; status: string };
type CourseRef = { id: string; title: string; is_published: boolean };

type Props = {
    product: Product;
    courses: CourseRef[];
    available: CourseRef[];
    options: { kinds: string[]; statuses: string[] };
    can: { manage: boolean };
};

export default function ProductShow({ product, courses, available, options, can }: Props) {
    return (
        <StudioLayout title={product.name}>
            <Head title={product.name} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href="/ops/products" className="hover:underline">
                    ← Products
                </Link>
                <span aria-hidden>·</span>
                <span className="font-mono text-xs">{product.sku}</span>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card title="Courses in this product">
                        <p className="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
                            Anyone holding this product gains access to these courses' published versions.
                        </p>
                        {courses.length === 0 ? (
                            <p className="text-sm text-zinc-500 dark:text-zinc-400">No courses yet.</p>
                        ) : (
                            <ul className="space-y-2">
                                {courses.map((c) => (
                                    <li key={c.id} className="flex items-center gap-2 text-sm">
                                        <span className="flex-1">{c.title}</span>
                                        {!c.is_published && (
                                            <span className="rounded-full border border-amber-300 px-2 py-0.5 text-xs text-amber-700 dark:border-amber-800 dark:text-amber-300">
                                                not published
                                            </span>
                                        )}
                                        {can.manage && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.delete(`/ops/products/${product.id}/courses/${c.id}`, {
                                                        preserveScroll: true,
                                                    })
                                                }
                                                className="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {can.manage && <AddCourse productId={product.id} available={available} />}
                </div>

                <div className="space-y-6">
                    <DetailsForm product={product} options={options} canManage={can.manage} />
                </div>
            </div>
        </StudioLayout>
    );
}

function AddCourse({ productId, available }: { productId: string; available: CourseRef[] }) {
    const [query, setQuery] = useState('');
    const filtered = available.filter((c) => c.title.toLowerCase().includes(query.toLowerCase()));

    function add(courseId: string) {
        router.post(`/ops/products/${productId}/courses`, { course_id: courseId }, { preserveScroll: true });
    }

    return (
        <Card title="Add a course">
            {available.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">Every course is already in this product.</p>
            ) : (
                <>
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Filter…"
                        className="mb-3 w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950"
                    />
                    <ul className="max-h-80 space-y-1 overflow-y-auto">
                        {filtered.map((c) => (
                            <li key={c.id} className="flex items-center gap-2 border-b border-zinc-100 py-1 text-sm dark:border-zinc-800">
                                <span className="flex-1">{c.title}</span>
                                {!c.is_published && (
                                    <span className="text-xs text-amber-600">not published</span>
                                )}
                                <button
                                    type="button"
                                    onClick={() => add(c.id)}
                                    className="rounded-md border border-indigo-300 px-2 py-0.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950"
                                >
                                    Add
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Card>
    );
}

function DetailsForm({
    product,
    options,
    canManage,
}: {
    product: Product;
    options: { kinds: string[]; statuses: string[] };
    canManage: boolean;
}) {
    const { data, setData, patch, processing, errors } = useForm({
        sku: product.sku,
        name: product.name,
        kind: product.kind,
        status: product.status,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        patch(`/ops/products/${product.id}`, { preserveScroll: true });
    }

    return (
        <Card title="Details">
            <form onSubmit={submit} className="space-y-3">
                <Field label="Name" error={errors.name}>
                    <input disabled={!canManage} value={data.name} onChange={(e) => setData('name', e.target.value)} className={input} />
                </Field>
                <Field label="SKU" error={errors.sku}>
                    <input disabled={!canManage} value={data.sku} onChange={(e) => setData('sku', e.target.value)} className={input} />
                </Field>
                <Field label="Kind" error={errors.kind}>
                    <select disabled={!canManage} value={data.kind} onChange={(e) => setData('kind', e.target.value)} className={input}>
                        {options.kinds.map((k) => (
                            <option key={k} value={k}>
                                {k}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Status" error={errors.status}>
                    <select disabled={!canManage} value={data.status} onChange={(e) => setData('status', e.target.value)} className={input}>
                        {options.statuses.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </Field>
                {canManage && (
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Save
                    </button>
                )}
            </form>
        </Card>
    );
}

const input = 'w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900';

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

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <label className="block text-xs font-medium text-zinc-600 dark:text-zinc-400">{label}</label>
            {children}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}
