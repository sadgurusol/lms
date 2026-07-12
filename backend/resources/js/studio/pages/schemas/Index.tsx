import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';

type VersionSummary = {
    id: string;
    version: number;
    status: string;
    published_at: string | null;
};

type SchemaRow = {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    version_count: number;
    course_count: number;
    draft: VersionSummary | null;
    published: VersionSummary | null;
};

type Can = { create: boolean; delete: boolean };

export default function SchemasIndex({ schemas, can }: { schemas: SchemaRow[]; can: Can }) {
    const [creating, setCreating] = useState(false);

    return (
        <StudioLayout title="Course schemas">
            <Head title="Schemas" />

            <p className="mb-6 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                A schema is a blueprint: <em>Part → Chapter → Topic</em>. Courses bind to a schema{' '}
                <strong>version</strong>, never to the schema itself, so publishing a version freezes it
                and every course authored against it keeps its meaning.
            </p>

            {can.create && !creating && (
                <button
                    type="button"
                    onClick={() => setCreating(true)}
                    className="mb-6 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    New schema
                </button>
            )}

            {creating && <CreateForm onCancel={() => setCreating(false)} />}

            {schemas.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No schemas yet.</p>
            ) : (
                <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    {schemas.map((schema) => (
                        <li key={schema.id} className="flex items-center gap-4 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{schema.name}</p>
                                <p className="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                    <code className="text-xs">{schema.slug}</code>
                                    {schema.description ? ` · ${schema.description}` : ''}
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                {schema.published && (
                                    <VersionChip version={schema.published} tone="published" />
                                )}
                                {schema.draft && <VersionChip version={schema.draft} tone="draft" />}
                                {!schema.published && !schema.draft && (
                                    <span className="text-sm text-zinc-400">no versions</span>
                                )}
                                {can.delete && <DeleteButton schema={schema} />}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </StudioLayout>
    );
}

function DeleteButton({ schema }: { schema: SchemaRow }) {
    // A schema bound to courses cannot be deleted; say why rather than offering
    // an action that will only bounce back with an error.
    if (schema.course_count > 0) {
        return (
            <span
                className="rounded-md px-2 py-1 text-xs text-zinc-400"
                title="Retire the courses on this schema before deleting it."
            >
                in use · {schema.course_count} course{schema.course_count === 1 ? '' : 's'}
            </span>
        );
    }

    const confirm = useConfirm();

    async function remove() {
        const ok = await confirm({
            title: 'Delete schema',
            message: (
                <>
                    Delete <strong>{schema.name}</strong> (<code>{schema.slug}</code>)? This cannot be
                    undone from here.
                </>
            ),
            confirmLabel: 'Delete',
            danger: true,
        });
        if (ok) router.delete(`/studio/schemas/${schema.id}`, { preserveScroll: true });
    }

    return (
        <button
            type="button"
            onClick={remove}
            className="rounded-md px-2 py-1 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
        >
            Delete
        </button>
    );
}

function VersionChip({ version, tone }: { version: VersionSummary; tone: 'draft' | 'published' }) {
    const styles =
        tone === 'published'
            ? 'border-emerald-300 text-emerald-800 dark:border-emerald-800 dark:text-emerald-200'
            : 'border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-200';

    return (
        <Link
            href={`/studio/schema-versions/${version.id}`}
            className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${styles}`}
        >
            v{version.version} · {tone}
        </Link>
    );
}

function CreateForm({ onCancel }: { onCancel: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', description: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/schemas', { onSuccess: () => reset() });
    }

    return (
        <form
            onSubmit={submit}
            className="mb-6 max-w-xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="schema-name">
                    Name
                </label>
                <input
                    id="schema-name"
                    autoFocus
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="Textbook (3-tier)"
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                {errors.name && (
                    <p role="alert" className="text-sm text-red-600">
                        {errors.name}
                    </p>
                )}
            </div>

            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="schema-description">
                    Description
                </label>
                <textarea
                    id="schema-description"
                    rows={2}
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Create draft
                </button>
                <button type="button" onClick={onCancel} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}
