import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type ClientRow = {
    id: string;
    name: string;
    slug: string;
    status: string;
    integration: string;
    user_count: number;
    entitlement_count: number;
};

type Props = {
    clients: ClientRow[];
    options: { statuses: string[]; integrations: string[] };
    can: { create: boolean };
};

const STATUS_STYLES: Record<string, string> = {
    pending: 'border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-200',
    active: 'border-emerald-300 text-emerald-800 dark:border-emerald-800 dark:text-emerald-200',
    suspended: 'border-red-300 text-red-800 dark:border-red-800 dark:text-red-200',
    terminated: 'border-zinc-300 text-zinc-500 dark:border-zinc-700 dark:text-zinc-500',
};

const INTEGRATION_LABEL: Record<string, string> = {
    none: 'No launch',
    lti_1_3: 'LTI 1.3',
    custom_jwt: 'Custom JWT',
};

export default function ClientsIndex({ clients, options, can }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <StudioLayout title="Clients">
            <Head title="Clients" />

            <p className="mb-6 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                B2B integrators — schools and organisations whose users reach published content through
                a launch from their own system.
            </p>

            {can.create && !creating && (
                <button
                    type="button"
                    onClick={() => setCreating(true)}
                    className="mb-6 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    New client
                </button>
            )}

            {creating && <CreateForm options={options} onCancel={() => setCreating(false)} />}

            {clients.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No clients yet.</p>
            ) : (
                <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    {clients.map((client) => (
                        <li key={client.id} className="flex items-center gap-4 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <Link
                                    href={`/ops/clients/${client.id}`}
                                    className="font-medium hover:text-indigo-600 hover:underline dark:hover:text-indigo-400"
                                >
                                    {client.name}
                                </Link>
                                <p className="text-sm text-zinc-500 dark:text-zinc-400">
                                    <span className="font-mono text-xs">{client.slug}</span> ·{' '}
                                    {INTEGRATION_LABEL[client.integration] ?? client.integration} · {client.user_count}{' '}
                                    {client.user_count === 1 ? 'user' : 'users'} · {client.entitlement_count}{' '}
                                    {client.entitlement_count === 1 ? 'entitlement' : 'entitlements'}
                                </p>
                            </div>
                            <span
                                className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                    STATUS_STYLES[client.status] ?? STATUS_STYLES.pending
                                }`}
                            >
                                {client.status}
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
    options: { statuses: string[]; integrations: string[] };
    onCancel: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        slug: '',
        status: 'pending',
        integration: 'none',
        contact_email: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/ops/clients', { onSuccess: () => reset() });
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
                    placeholder="ABC School"
                    className={input}
                />
            </Field>

            <Field label="Slug (blank = from name)" error={errors.slug}>
                <input
                    value={data.slug}
                    onChange={(e) => setData('slug', e.target.value)}
                    placeholder="abc-school"
                    className={input}
                />
            </Field>

            <div className="grid grid-cols-2 gap-3">
                <Field label="Status" error={errors.status}>
                    <select value={data.status} onChange={(e) => setData('status', e.target.value)} className={input}>
                        {options.statuses.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Integration" error={errors.integration}>
                    <select
                        value={data.integration}
                        onChange={(e) => setData('integration', e.target.value)}
                        className={input}
                    >
                        {options.integrations.map((i) => (
                            <option key={i} value={i}>
                                {i}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            <Field label="Contact email" error={errors.contact_email}>
                <input
                    value={data.contact_email}
                    onChange={(e) => setData('contact_email', e.target.value)}
                    placeholder="it@abcschool.edu"
                    className={input}
                />
            </Field>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Create client
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
