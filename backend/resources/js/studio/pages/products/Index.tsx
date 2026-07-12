import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type ProductRow = {
    id: string;
    sku: string;
    name: string;
    kind: string;
    status: string;
    course_count: number;
};

type Props = {
    products: ProductRow[];
    options: { kinds: string[]; statuses: string[] };
    can: { create: boolean };
};

const STATUS_STYLES: Record<string, string> = {
    draft: 'border-zinc-300 text-zinc-600 dark:border-zinc-700 dark:text-zinc-400',
    active: 'border-emerald-300 text-emerald-800 dark:border-emerald-800 dark:text-emerald-200',
    retired: 'border-zinc-300 text-zinc-500 dark:border-zinc-700 dark:text-zinc-500',
};

export default function ProductsIndex({ products, options, can }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <StudioLayout title="Products">
            <Head title="Products" />

            <p className="mb-6 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                The catalogue. A product bundles one or more published courses; clients and subscribers
                gain access to a product, not to courses directly.
            </p>

            {can.create && !creating && (
                <button
                    type="button"
                    onClick={() => setCreating(true)}
                    className="mb-6 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    New product
                </button>
            )}

            {creating && <CreateForm options={options} onCancel={() => setCreating(false)} />}

            {products.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No products yet.</p>
            ) : (
                <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    {products.map((product) => (
                        <li key={product.id} className="flex items-center gap-4 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <Link
                                    href={`/ops/products/${product.id}`}
                                    className="font-medium hover:text-indigo-600 hover:underline dark:hover:text-indigo-400"
                                >
                                    {product.name}
                                </Link>
                                <p className="text-sm text-zinc-500 dark:text-zinc-400">
                                    <span className="font-mono text-xs">{product.sku}</span> · {product.kind} ·{' '}
                                    {product.course_count}{' '}
                                    {product.course_count === 1 ? 'course' : 'courses'}
                                </p>
                            </div>
                            <span
                                className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                    STATUS_STYLES[product.status] ?? STATUS_STYLES.draft
                                }`}
                            >
                                {product.status}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </StudioLayout>
    );
}

function CreateForm({
    options,
    onCancel,
}: {
    options: { kinds: string[]; statuses: string[] };
    onCancel: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        sku: '',
        name: '',
        kind: 'course',
        status: 'draft',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/ops/products', { onSuccess: () => reset() });
    }

    return (
        <form
            onSubmit={submit}
            className="mb-6 max-w-xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
            <Field label="Name" error={errors.name}>
                <input
                    autoFocus
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="Grade 10 English"
                    className={input}
                />
            </Field>

            <Field label="SKU" error={errors.sku}>
                <input
                    value={data.sku}
                    onChange={(e) => setData('sku', e.target.value)}
                    placeholder="ENG-10"
                    className={input}
                />
            </Field>

            <div className="grid grid-cols-2 gap-3">
                <Field label="Kind" error={errors.kind}>
                    <select value={data.kind} onChange={(e) => setData('kind', e.target.value)} className={input}>
                        {options.kinds.map((k) => (
                            <option key={k} value={k}>
                                {k}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Status" error={errors.status}>
                    <select value={data.status} onChange={(e) => setData('status', e.target.value)} className={input}>
                        {options.statuses.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Create product
                </button>
                <button type="button" onClick={onCancel} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}

const input = 'w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900';

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
            <label className="block text-sm font-medium">{label}</label>
            {children}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}
