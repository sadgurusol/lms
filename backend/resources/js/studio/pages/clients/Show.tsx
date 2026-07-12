import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';
import type { FlashMessages } from '@/studio/types/global';

type Client = {
    id: string;
    name: string;
    slug: string;
    status: string;
    integration: string;
    contact_email: string | null;
    user_count: number;
    entitlement_count: number;
    report_webhook_url: string | null;
    has_webhook_secret: boolean;
    ai_tutor_enabled: boolean;
};

type Key = { id: string; kid: string; algorithm: string; status: string; expires_at: string | null };
type Entitlement = {
    id: string;
    product_id: string;
    product: string | null;
    seat_model: string;
    seat_limit: number | null;
    status: string;
    starts_at: string;
    ends_at: string | null;
    contract_ref: string | null;
};

type ProductRef = { id: string; name: string; sku: string };

type Options = {
    statuses: string[];
    integrations: string[];
    seat_models: string[];
    entitlement_statuses: string[];
};

type Props = {
    client: Client;
    keys: Key[];
    entitlements: Entitlement[];
    products: ProductRef[];
    options: Options;
    can: { manage: boolean; manage_entitlements: boolean; manage_keys: boolean };
};

export default function ClientShow({ client, keys, entitlements, products, options, can }: Props) {
    return (
        <StudioLayout title={client.name}>
            <Head title={client.name} />

            <div className="mb-6 flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                <Link href="/ops/clients" className="hover:underline">
                    ← Clients
                </Link>
                <span aria-hidden>·</span>
                <span className="font-mono text-xs">{client.slug}</span>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Entitlements
                        clientId={client.id}
                        entitlements={entitlements}
                        products={products}
                        options={options}
                        canManage={can.manage_entitlements}
                    />

                    <LaunchKeys clientId={client.id} keys={keys} canManage={can.manage_keys} />
                    <Webhook client={client} canManage={can.manage} canRotate={can.manage_keys} />
                </div>

                <div className="space-y-6">
                    <DetailsForm client={client} options={options} canManage={can.manage} />
                    <Features client={client} canManage={can.manage} />
                    <Card title="Summary">
                        <dl className="space-y-1 text-sm">
                            <Row label="Users" value={String(client.user_count)} />
                            <Row label="Entitlements" value={String(client.entitlement_count)} />
                        </dl>
                    </Card>
                </div>
            </div>
        </StudioLayout>
    );
}

function Features({ client, canManage }: { client: Client; canManage: boolean }) {
    function toggleTutor(enabled: boolean) {
        router.patch(`/ops/clients/${client.id}/ai-tutor`, { enabled }, { preserveScroll: true });
    }

    return (
        <Card title="Features">
            <label className="flex items-start justify-between gap-3">
                <span>
                    <span className="block text-sm font-medium">AI tutor</span>
                    <span className="block text-xs text-zinc-500 dark:text-zinc-400">
                        Let this client's learners chat with the course tutor.
                    </span>
                </span>
                <input
                    type="checkbox"
                    checked={client.ai_tutor_enabled}
                    disabled={!canManage}
                    onChange={(e) => toggleTutor(e.target.checked)}
                    className="mt-1 h-5 w-5 shrink-0 accent-indigo-600 disabled:opacity-50"
                />
            </label>
        </Card>
    );
}

const SEAT_HELP: Record<string, string> = {
    assigned: 'Named seats — each learner must be assigned one.',
    active: 'Billed on active users; access is never blocked, overage is reported.',
    unlimited: 'No seat limit.',
};

function Entitlements({
    clientId,
    entitlements,
    products,
    options,
    canManage,
}: {
    clientId: string;
    entitlements: Entitlement[];
    products: ProductRef[];
    options: Options;
    canManage: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);
    const confirm = useConfirm();

    async function removeEntitlement(id: string) {
        const ok = await confirm({
            title: 'Remove entitlement',
            message: 'Remove this entitlement? The client loses access to the product it grants.',
            confirmLabel: 'Remove',
            danger: true,
        });
        if (ok) router.delete(`/ops/entitlements/${id}`, { preserveScroll: true });
    }

    return (
        <Card title="Entitlements">
            <p className="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
                What this client's users may access: a product, on seat terms, for a window.
            </p>

            {entitlements.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No entitlements yet.</p>
            ) : (
                <ul className="mb-3 space-y-2">
                    {entitlements.map((e) =>
                        editingId === e.id ? (
                            <li key={e.id}>
                                <EntitlementForm
                                    clientId={clientId}
                                    entitlement={e}
                                    products={products}
                                    options={options}
                                    onDone={() => setEditingId(null)}
                                />
                            </li>
                        ) : (
                            <li
                                key={e.id}
                                className="flex items-center gap-2 rounded-md border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">{e.product ?? 'Unknown product'}</p>
                                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                                        {e.seat_model}
                                        {e.seat_limit !== null && ` · ${e.seat_limit} seats`} · {e.status} · from{' '}
                                        {e.starts_at}
                                        {e.ends_at && ` to ${e.ends_at}`}
                                    </p>
                                </div>
                                {canManage && (
                                    <div className="flex gap-1 text-xs">
                                        <button
                                            type="button"
                                            onClick={() => setEditingId(e.id)}
                                            className="rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => void removeEntitlement(e.id)}
                                            className="rounded px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                )}
                            </li>
                        ),
                    )}
                </ul>
            )}

            {canManage &&
                (adding ? (
                    <EntitlementForm
                        clientId={clientId}
                        products={products}
                        options={options}
                        onDone={() => setAdding(false)}
                    />
                ) : products.length === 0 ? (
                    <p className="text-sm text-amber-600">
                        Create a product first, then grant it here.
                    </p>
                ) : (
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                    >
                        Grant a product
                    </button>
                ))}
        </Card>
    );
}

function EntitlementForm({
    clientId,
    entitlement,
    products,
    options,
    onDone,
}: {
    clientId: string;
    entitlement?: Entitlement;
    products: ProductRef[];
    options: Options;
    onDone: () => void;
}) {
    const today = new Date().toISOString().slice(0, 10);
    const { data, setData, post, patch, processing, errors } = useForm({
        product_id: entitlement?.product_id ?? products[0]?.id ?? '',
        seat_model: entitlement?.seat_model ?? 'active',
        seat_limit: entitlement?.seat_limit ?? null,
        starts_at: entitlement?.starts_at ?? today,
        ends_at: entitlement?.ends_at ?? '',
        status: entitlement?.status ?? 'active',
        contract_ref: entitlement?.contract_ref ?? '',
    });

    const unlimited = data.seat_model === 'unlimited';

    function submit(event: FormEvent) {
        event.preventDefault();
        if (entitlement) patch(`/ops/entitlements/${entitlement.id}`, { preserveScroll: true, onSuccess: onDone });
        else post(`/ops/clients/${clientId}/entitlements`, { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-md border border-indigo-200 bg-indigo-50/40 p-3 dark:border-indigo-900 dark:bg-indigo-950/30"
        >
            <Field label="Product" error={errors.product_id}>
                <select
                    value={data.product_id}
                    onChange={(e) => setData('product_id', e.target.value)}
                    disabled={entitlement !== undefined}
                    className={input}
                >
                    {products.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name} ({p.sku})
                        </option>
                    ))}
                </select>
                {entitlement && <p className="text-xs text-zinc-500">Product can't change — add a new grant instead.</p>}
            </Field>

            <Field label="Seat model" error={errors.seat_model}>
                <select value={data.seat_model} onChange={(e) => setData('seat_model', e.target.value)} className={input}>
                    {options.seat_models.map((m) => (
                        <option key={m} value={m}>
                            {m}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-zinc-500 dark:text-zinc-400">{SEAT_HELP[data.seat_model]}</p>
            </Field>

            {!unlimited && (
                <Field label="Seat limit" error={errors.seat_limit}>
                    <input
                        value={data.seat_limit ?? ''}
                        onChange={(e) => setData('seat_limit', e.target.value === '' ? null : Number(e.target.value))}
                        className={input}
                        inputMode="numeric"
                    />
                </Field>
            )}

            <div className="grid grid-cols-2 gap-2">
                <Field label="Starts" error={errors.starts_at}>
                    <input type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} className={input} />
                </Field>
                <Field label="Ends (optional)" error={errors.ends_at}>
                    <input type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} className={input} />
                </Field>
            </div>

            <Field label="Status" error={errors.status}>
                <select value={data.status} onChange={(e) => setData('status', e.target.value)} className={input}>
                    {options.entitlement_statuses.map((s) => (
                        <option key={s} value={s}>
                            {s}
                        </option>
                    ))}
                </select>
            </Field>

            <Field label="Contract ref (optional)" error={errors.contract_ref}>
                <input value={data.contract_ref} onChange={(e) => setData('contract_ref', e.target.value)} className={input} />
            </Field>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    {entitlement ? 'Save' : 'Grant'}
                </button>
                <button type="button" onClick={onDone} className="rounded-md px-3 py-1.5 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}

function LaunchKeys({ clientId, keys, canManage }: { clientId: string; keys: Key[]; canManage: boolean }) {
    const [adding, setAdding] = useState(false);
    const confirm = useConfirm();

    async function revokeKey(k: Key) {
        const ok = await confirm({
            title: 'Revoke launch key',
            message: (
                <>
                    Revoke key <code>{k.kid}</code>? Launches signed with it will stop verifying immediately.
                </>
            ),
            confirmLabel: 'Revoke',
            danger: true,
        });
        if (ok) router.delete(`/ops/client-keys/${k.id}`, { preserveScroll: true });
    }

    return (
        <Card title="Launch keys">
            <p className="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
                The client's public keys. We verify the signed launch JWTs their system sends —
                asymmetric only (RS256 / ES256).
            </p>

            {keys.length === 0 ? (
                <p className="mb-3 text-sm text-zinc-500 dark:text-zinc-400">No keys registered.</p>
            ) : (
                <ul className="mb-3 space-y-2">
                    {keys.map((k) => (
                        <li key={k.id} className="flex items-center gap-2 rounded-md border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
                            <span className="min-w-0 flex-1 truncate font-mono text-xs">{k.kid}</span>
                            <span className="text-xs text-zinc-500 dark:text-zinc-400">{k.algorithm}</span>
                            <span
                                className={`rounded-full px-1.5 py-0.5 text-xs ${
                                    k.status === 'revoked'
                                        ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                                        : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                }`}
                            >
                                {k.status}
                            </span>
                            {canManage && k.status !== 'revoked' && (
                                <button
                                    type="button"
                                    onClick={() => void revokeKey(k)}
                                    className="text-xs text-red-600 hover:underline"
                                >
                                    Revoke
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (adding ? (
                <AddKeyForm clientId={clientId} onDone={() => setAdding(false)} />
            ) : (
                <button
                    type="button"
                    onClick={() => setAdding(true)}
                    className="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    Add key
                </button>
            ))}
        </Card>
    );
}

function AddKeyForm({ clientId, onDone }: { clientId: string; onDone: () => void }) {
    const [source, setSource] = useState<'pem' | 'jwks'>('pem');
    const { data, setData, processing, errors, reset } = useForm({
        kid: '',
        algorithm: 'RS256',
        public_key: '',
        jwks_url: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        // Send only the chosen source; the server requires exactly one.
        const payload = { ...data, public_key: source === 'pem' ? data.public_key : '', jwks_url: source === 'jwks' ? data.jwks_url : '' };
        router.post(`/ops/clients/${clientId}/keys`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    }

    return (
        <form onSubmit={submit} className="mt-3 space-y-3 rounded-md border border-indigo-200 bg-indigo-50/40 p-3 dark:border-indigo-900 dark:bg-indigo-950/30">
            <Field label="Key ID (kid)" error={errors.kid}>
                <input value={data.kid} onChange={(e) => setData('kid', e.target.value)} placeholder="2026-key-1" className={input} />
            </Field>
            <div className="grid grid-cols-2 gap-2">
                <Field label="Algorithm" error={errors.algorithm}>
                    <select value={data.algorithm} onChange={(e) => setData('algorithm', e.target.value)} className={input}>
                        <option value="RS256">RS256</option>
                        <option value="ES256">ES256</option>
                    </select>
                </Field>
                <Field label="Source">
                    <select value={source} onChange={(e) => setSource(e.target.value as 'pem' | 'jwks')} className={input}>
                        <option value="pem">PEM public key</option>
                        <option value="jwks">JWKS URL</option>
                    </select>
                </Field>
            </div>
            {source === 'pem' ? (
                <Field label="Public key (PEM)" error={errors.public_key}>
                    <textarea
                        value={data.public_key}
                        onChange={(e) => setData('public_key', e.target.value)}
                        rows={4}
                        placeholder="-----BEGIN PUBLIC KEY-----"
                        className={`${input} font-mono text-xs`}
                    />
                </Field>
            ) : (
                <Field label="JWKS URL" error={errors.jwks_url}>
                    <input value={data.jwks_url} onChange={(e) => setData('jwks_url', e.target.value)} placeholder="https://sis.example.edu/.well-known/jwks.json" className={input} />
                </Field>
            )}
            <div className="flex gap-2">
                <button type="submit" disabled={processing} className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                    Add key
                </button>
                <button type="button" onClick={onDone} className="rounded-md px-3 py-1.5 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}

function Webhook({ client, canManage, canRotate }: { client: Client; canManage: boolean; canRotate: boolean }) {
    // The rotated secret arrives once, via a flash prop, after its redirect.
    const flash = usePage().props.flash as FlashMessages;
    const secret = flash?.secret;
    const { data, setData, patch, processing, errors } = useForm({
        report_webhook_url: client.report_webhook_url ?? '',
    });

    const confirm = useConfirm();

    function save(event: FormEvent) {
        event.preventDefault();
        patch(`/ops/clients/${client.id}/webhook`, { preserveScroll: true });
    }

    async function rotate() {
        const ok = await confirm({
            title: client.has_webhook_secret ? 'Rotate signing secret' : 'Generate signing secret',
            message: client.has_webhook_secret
                ? 'Rotate the signing secret? The old one stops working immediately, so update the client before their next webhook.'
                : 'Generate a signing secret for this client? It is shown once.',
            confirmLabel: client.has_webhook_secret ? 'Rotate' : 'Generate',
            danger: client.has_webhook_secret,
        });
        if (ok) router.post(`/ops/clients/${client.id}/webhook/secret`, {}, { preserveScroll: true });
    }

    return (
        <Card title="Activity webhook">
            <p className="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
                Where we POST this client's learner activity, signed with a shared secret.
            </p>

            {secret && (
                <div className="mb-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950">
                    <p className="mb-1 font-medium text-amber-900 dark:text-amber-100">
                        Copy this secret now — it is shown only once.
                    </p>
                    <code className="block break-all rounded bg-white px-2 py-1 font-mono text-xs dark:bg-zinc-900">
                        {secret}
                    </code>
                </div>
            )}

            <form onSubmit={save} className="space-y-3">
                <Field label="Webhook URL" error={errors.report_webhook_url}>
                    <input
                        disabled={!canManage}
                        value={data.report_webhook_url}
                        onChange={(e) => setData('report_webhook_url', e.target.value)}
                        placeholder="https://sis.example.edu/lms/activity"
                        className={input}
                    />
                </Field>
                {canManage && (
                    <button type="submit" disabled={processing} className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                        Save URL
                    </button>
                )}
            </form>

            {canRotate && (
                <div className="mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-800">
                    <p className="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
                        {client.has_webhook_secret ? 'A signing secret is set.' : 'No signing secret yet.'}
                    </p>
                    <button
                        type="button"
                        onClick={() => void rotate()}
                        className="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    >
                        {client.has_webhook_secret ? 'Rotate secret' : 'Generate secret'}
                    </button>
                </div>
            )}
        </Card>
    );
}

function DetailsForm({
    client,
    options,
    canManage,
}: {
    client: Client;
    options: { statuses: string[]; integrations: string[] };
    canManage: boolean;
}) {
    const { data, setData, patch, processing, errors } = useForm({
        name: client.name,
        slug: client.slug,
        status: client.status,
        integration: client.integration,
        contact_email: client.contact_email ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        patch(`/ops/clients/${client.id}`, { preserveScroll: true });
    }

    return (
        <Card title="Details">
            <form onSubmit={submit} className="space-y-3">
                <Field label="Name" error={errors.name}>
                    <input
                        disabled={!canManage}
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className={input}
                    />
                </Field>
                <Field label="Slug" error={errors.slug}>
                    <input
                        disabled={!canManage}
                        value={data.slug}
                        onChange={(e) => setData('slug', e.target.value)}
                        className={input}
                    />
                </Field>
                <Field label="Status" error={errors.status}>
                    <select
                        disabled={!canManage}
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className={input}
                    >
                        {options.statuses.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Integration" error={errors.integration}>
                    <select
                        disabled={!canManage}
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
                <Field label="Contact email" error={errors.contact_email}>
                    <input
                        disabled={!canManage}
                        value={data.contact_email}
                        onChange={(e) => setData('contact_email', e.target.value)}
                        className={input}
                    />
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

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between">
            <dt className="text-zinc-500 dark:text-zinc-400">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}
