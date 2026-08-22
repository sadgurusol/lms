import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';
import { useConfirm } from '@/studio/components/ConfirmDialog';

export type Level = {
    id: string;
    parent_level_id: string | null;
    name: string;
    plural_name: string;
    depth: number;
    sort_key: string;
    min_occurrences: number;
    max_occurrences: number | null;
    allows_content: boolean;
    allowed_block_types: string[];
    allows_assessment: boolean;
    numbering_style: string;
    label_template: string;
};

type Props = {
    schema: { id: string; name: string; slug: string };
    version: { id: string; version: number; status: string; published_at: string | null; editable: boolean };
    levels: Level[];
    options: { block_types: string[]; numbering_styles: string[] };
    courses_bound: number;
    can: { update: boolean; publish: boolean; clone: boolean };
};

export default function SchemaShow({ schema, version, levels, options, courses_bound, can }: Props) {
    const [addingUnder, setAddingUnder] = useState<string | null | undefined>(undefined);
    const [editingLevel, setEditingLevel] = useState<Level | null>(null);

    const roots = levels.filter((l) => l.parent_level_id === null);

    return (
        <StudioLayout title={`${schema.name} · v${version.version}`}>
            <Head title={`${schema.name} v${version.version}`} />

            <StatusBar version={version} coursesBound={courses_bound} can={can} />

            <section className="mt-8">
                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500">Levels</h2>

                {levels.length === 0 ? (
                    <p className="text-sm text-zinc-500 dark:text-zinc-400">
                        No levels yet. A schema needs at least one root level, and at least one level that
                        allows content, before it can be published.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {roots.map((root) => (
                            <LevelBranch
                                key={root.id}
                                level={root}
                                levels={levels}
                                options={options}
                                editable={can.update}
                                onAddChild={(id) => {
                                    setEditingLevel(null);
                                    setAddingUnder(id);
                                }}
                                onEdit={(level) => {
                                    setAddingUnder(undefined);
                                    setEditingLevel(level);
                                }}
                            />
                        ))}
                    </ul>
                )}

                {can.update && roots.length === 0 && (
                    <button
                        type="button"
                        onClick={() => setAddingUnder(null)}
                        className="mt-4 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                    >
                        Add root level
                    </button>
                )}
            </section>

            {addingUnder !== undefined && can.update && (
                <LevelForm
                    key="add"
                    versionId={version.id}
                    parentLevelId={addingUnder}
                    parentName={levels.find((l) => l.id === addingUnder)?.name ?? null}
                    options={options}
                    onClose={() => setAddingUnder(undefined)}
                />
            )}

            {editingLevel && can.update && (
                <LevelForm
                    key={editingLevel.id}
                    versionId={version.id}
                    parentLevelId={editingLevel.parent_level_id}
                    parentName={null}
                    level={editingLevel}
                    options={options}
                    onClose={() => setEditingLevel(null)}
                />
            )}
        </StudioLayout>
    );
}

function StatusBar({
    version,
    coursesBound,
    can,
}: {
    version: Props['version'];
    coursesBound: number;
    can: Props['can'];
}) {
    return (
        <div className="flex flex-wrap items-center gap-4 rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <span
                className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                    version.editable
                        ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                        : 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                }`}
            >
                {version.status}
            </span>

            {coursesBound > 0 && (
                <span className="text-sm text-zinc-500 dark:text-zinc-400">
                    {coursesBound} course{coursesBound === 1 ? '' : 's'} bound to this version
                </span>
            )}

            {!version.editable && (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">
                    Published versions are immutable. Clone into a draft to change anything.
                </p>
            )}

            <div className="ml-auto flex gap-2">
                {can.publish && (
                    <button
                        type="button"
                        onClick={() => router.post(`/studio/schema-versions/${version.id}/publish`)}
                        className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500"
                    >
                        Publish version
                    </button>
                )}
                {can.clone && (
                    <button
                        type="button"
                        onClick={() => router.post(`/studio/schema-versions/${version.id}/clone`)}
                        className="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium dark:border-zinc-700"
                    >
                        Clone to new draft
                    </button>
                )}
            </div>
        </div>
    );
}

function LevelBranch({
    level,
    levels,
    options,
    editable,
    onAddChild,
    onEdit,
}: {
    level: Level;
    levels: Level[];
    options: Props['options'];
    editable: boolean;
    onAddChild: (parentId: string) => void;
    onEdit: (level: Level) => void;
}) {
    const children = levels.filter((l) => l.parent_level_id === level.id);

    return (
        <li>
            <LevelCard level={level} options={options} editable={editable} onAddChild={onAddChild} onEdit={onEdit} />

            {children.length > 0 && (
                <ul className="mt-2 space-y-2 border-l border-zinc-200 pl-6 dark:border-zinc-800">
                    {children.map((child) => (
                        <LevelBranch
                            key={child.id}
                            level={child}
                            levels={levels}
                            options={options}
                            editable={editable}
                            onAddChild={onAddChild}
                            onEdit={onEdit}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

function LevelCard({
    level,
    options,
    editable,
    onAddChild,
    onEdit,
}: {
    level: Level;
    options: Props['options'];
    editable: boolean;
    onAddChild: (parentId: string) => void;
    onEdit: (level: Level) => void;
}) {
    const confirm = useConfirm();

    async function remove() {
        const ok = await confirm({
            title: 'Remove level',
            message: (
                <>
                    Remove <strong>{level.name}</strong> and every level beneath it? This draft has no
                    courses bound to it.
                </>
            ),
            confirmLabel: 'Remove',
            danger: true,
        });
        if (ok) router.delete(`/studio/schema-levels/${level.id}`);
    }

    const cardinality =
        level.max_occurrences === null
            ? `${level.min_occurrences}+`
            : `${level.min_occurrences}–${level.max_occurrences}`;

    return (
        <div className="rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="font-medium">{level.name}</span>
                <span className="text-xs text-zinc-400">/ {level.plural_name}</span>
                <code className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">
                    {level.label_template}
                </code>
                <span className="text-xs text-zinc-500">{cardinality} per parent</span>
                <span className="text-xs text-zinc-500">numbering: {level.numbering_style}</span>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-2">
                {level.allows_content ? (
                    level.allowed_block_types.map((type) => (
                        <span
                            key={type}
                            className="rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200"
                        >
                            {type}
                        </span>
                    ))
                ) : (
                    <span className="text-xs text-zinc-400">no content — grouping only</span>
                )}

                {level.allows_assessment && (
                    <span className="rounded bg-purple-50 px-1.5 py-0.5 text-xs text-purple-800 dark:bg-purple-950 dark:text-purple-200">
                        assessments
                    </span>
                )}

                {editable && (
                    <div className="ml-auto flex gap-2">
                        <button
                            type="button"
                            onClick={() => onAddChild(level.id)}
                            className="text-xs font-medium text-indigo-600 hover:underline"
                        >
                            Add {options.block_types.length > 0 ? 'child level' : 'child'}
                        </button>
                        <button
                            type="button"
                            onClick={() => onEdit(level)}
                            className="text-xs font-medium text-zinc-600 hover:underline dark:text-zinc-300"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            onClick={() => void remove()}
                            className="text-xs font-medium text-red-600 hover:underline"
                        >
                            Remove
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

function LevelForm({
    versionId,
    parentLevelId,
    parentName,
    level,
    options,
    onClose,
}: {
    versionId: string;
    parentLevelId: string | null;
    parentName: string | null;
    level?: Level;
    options: Props['options'];
    onClose: () => void;
}) {
    const editing = level !== undefined;
    const { data, setData, post, patch, processing, errors } = useForm({
        // parent_level_id is fixed once a level exists — the server ignores it on
        // update — but is still sent so the create path has it.
        parent_level_id: level?.parent_level_id ?? parentLevelId,
        name: level?.name ?? '',
        plural_name: level?.plural_name ?? '',
        min_occurrences: level?.min_occurrences ?? 1,
        max_occurrences: (level?.max_occurrences ?? null) as number | null,
        allows_content: level?.allows_content ?? false,
        allowed_block_types: (level?.allowed_block_types ?? []) as string[],
        allows_assessment: level?.allows_assessment ?? false,
        numbering_style: level?.numbering_style ?? 'numeric',
        label_template: level?.label_template ?? '{n}. {title}',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (editing) patch(`/studio/schema-levels/${level.id}`, { onSuccess: onClose });
        else post(`/studio/schema-versions/${versionId}/levels`, { onSuccess: onClose });
    }

    function toggleBlockType(type: string) {
        setData(
            'allowed_block_types',
            data.allowed_block_types.includes(type)
                ? data.allowed_block_types.filter((t) => t !== type)
                : [...data.allowed_block_types, type],
        );
    }

    return (
        <form
            onSubmit={submit}
            className="mt-6 max-w-2xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
            <h2 className="font-medium">
                {editing
                    ? `Edit ${level.name}`
                    : parentName
                      ? `New level under ${parentName}`
                      : 'New root level'}
            </h2>

            <div className="grid grid-cols-2 gap-4">
                <Field label="Name" error={errors.name}>
                    <input
                        autoFocus
                        value={data.name}
                        onChange={(e) => {
                            setData('name', e.target.value);
                            if (!data.plural_name) setData('plural_name', `${e.target.value}s`);
                        }}
                        placeholder="Chapter"
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    />
                </Field>

                <Field label="Plural" error={errors.plural_name}>
                    <input
                        value={data.plural_name}
                        onChange={(e) => setData('plural_name', e.target.value)}
                        placeholder="Chapters"
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    />
                </Field>

                <Field label="Minimum per parent" error={errors.min_occurrences} hint="Checked at publish, not on save">
                    <input
                        type="number"
                        min={0}
                        value={data.min_occurrences}
                        onChange={(e) => setData('min_occurrences', Number(e.target.value))}
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    />
                </Field>

                <Field label="Maximum per parent" error={errors.max_occurrences} hint="Blank for unbounded">
                    <input
                        type="number"
                        min={1}
                        value={data.max_occurrences ?? ''}
                        onChange={(e) =>
                            setData('max_occurrences', e.target.value === '' ? null : Number(e.target.value))
                        }
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    />
                </Field>

                <Field label="Numbering" error={errors.numbering_style}>
                    <select
                        value={data.numbering_style}
                        onChange={(e) => setData('numbering_style', e.target.value)}
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        {options.numbering_styles.map((style) => (
                            <option key={style} value={style}>
                                {style}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Label template" error={errors.label_template} hint="{n} and {title}">
                    <input
                        value={data.label_template}
                        onChange={(e) => setData('label_template', e.target.value)}
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    />
                </Field>
            </div>

            <fieldset className="space-y-2">
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={data.allows_content}
                        onChange={(e) => setData('allows_content', e.target.checked)}
                    />
                    Nodes at this level may carry content
                </label>

                {data.allows_content && (
                    <div className="ml-6 space-y-1">
                        <p className="text-xs text-zinc-500">
                            Authors will see exactly these block types on this level, and no others.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            {options.block_types.map((type) => (
                                <label key={type} className="flex items-center gap-1.5 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={data.allowed_block_types.includes(type)}
                                        onChange={() => toggleBlockType(type)}
                                    />
                                    {type}
                                </label>
                            ))}
                        </div>
                        {errors.allowed_block_types && (
                            <p role="alert" className="text-sm text-red-600">
                                {errors.allowed_block_types}
                            </p>
                        )}
                    </div>
                )}

                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={data.allows_assessment}
                        onChange={(e) => setData('allows_assessment', e.target.checked)}
                    />
                    Quizzes and tests may attach here
                </label>
            </fieldset>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    {editing ? 'Save changes' : 'Add level'}
                </button>
                <button type="button" onClick={onClose} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}

function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <label className="block text-sm font-medium">{label}</label>
            {children}
            {hint && <p className="text-xs text-zinc-500">{hint}</p>}
            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
