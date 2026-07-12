import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type SchemaVersionOption = { id: string; label: string };

type CourseRow = {
    id: string;
    title: string;
    code: string | null;
    subject: string | null;
    grade_band: string | null;
    language: string;
    workflow_state: string;
    node_count: number;
    published_number: number | null;
    has_pending_draft: boolean;
    schema: { name: string; version: number };
};

type Props = {
    courses: CourseRow[];
    schema_versions: SchemaVersionOption[];
    can: { create: boolean };
};

const STATE_STYLES: Record<string, string> = {
    draft: 'border-zinc-300 text-zinc-700 dark:border-zinc-700 dark:text-zinc-300',
    in_review: 'border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-200',
    changes_requested: 'border-red-300 text-red-800 dark:border-red-800 dark:text-red-200',
    approved: 'border-sky-300 text-sky-800 dark:border-sky-800 dark:text-sky-200',
    published: 'border-emerald-300 text-emerald-800 dark:border-emerald-800 dark:text-emerald-200',
    archived: 'border-zinc-300 text-zinc-500 dark:border-zinc-700 dark:text-zinc-500',
};

export default function CoursesIndex({ courses, schema_versions, can }: Props) {
    const [creating, setCreating] = useState(false);

    // A course binds to a *published* schema version. With none published there
    // is nothing to bind to, so say that rather than showing a dead dropdown.
    const blocked = schema_versions.length === 0;

    return (
        <StudioLayout title="Courses">
            <Head title="Courses" />

            {can.create && !creating && (
                <div className="mb-6">
                    <button
                        type="button"
                        disabled={blocked}
                        onClick={() => setCreating(true)}
                        className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        New course
                    </button>

                    {blocked && (
                        <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            No schema version is published yet. A course is built on a published
                            schema, so <Link href="/studio/schemas" className="text-indigo-600 underline">publish one first</Link>.
                        </p>
                    )}
                </div>
            )}

            {creating && (
                <CreateForm
                    versions={schema_versions}
                    onCancel={() => setCreating(false)}
                    onCreated={() => setCreating(false)}
                />
            )}

            {courses.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">
                    No courses yet.
                </p>
            ) : (
                <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    {courses.map((course) => (
                        <li key={course.id} className="flex items-center gap-4 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">
                                    <Link
                                        href={`/studio/courses/${course.id}`}
                                        className="hover:text-indigo-600 hover:underline dark:hover:text-indigo-400"
                                    >
                                        {course.title}
                                    </Link>
                                    {course.code && (
                                        <span className="ml-2 font-mono text-xs text-zinc-500">
                                            {course.code}
                                        </span>
                                    )}
                                </p>
                                <p className="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                    {course.schema.name} · v{course.schema.version} ·{' '}
                                    {course.node_count} {course.node_count === 1 ? 'node' : 'nodes'}
                                    {course.subject && ` · ${course.subject}`}
                                    {course.grade_band && ` · ${course.grade_band}`}
                                </p>
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                                {course.published_number !== null && (
                                    <span className="rounded-full border border-emerald-300 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:border-emerald-800 dark:text-emerald-200">
                                        Live v{course.published_number}
                                    </span>
                                )}
                                {/* Show the draft state only when it is not itself the live state. */}
                                {(course.published_number === null || course.has_pending_draft) && (
                                    <span
                                        className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                            STATE_STYLES[course.workflow_state] ?? STATE_STYLES.draft
                                        }`}
                                    >
                                        {course.has_pending_draft && course.published_number !== null
                                            ? `draft v${course.published_number + 1}`
                                            : course.workflow_state.replace('_', ' ')}
                                    </span>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </StudioLayout>
    );
}

function CreateForm({
    versions,
    onCancel,
    onCreated,
}: {
    versions: SchemaVersionOption[];
    onCancel: () => void;
    onCreated: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        code: '',
        subject: '',
        grade_band: '',
        language: 'en',
        schema_version_id: versions[0]?.id ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/courses', {
            onSuccess: () => {
                reset();
                onCreated();
            },
        });
    }

    return (
        <form
            onSubmit={submit}
            className="mb-6 max-w-2xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
            <Field label="Title" error={errors.title} htmlFor="course-title">
                <input
                    id="course-title"
                    autoFocus
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="Grade 10 English"
                    className={inputClass}
                />
            </Field>

            <Field
                label="Schema version"
                error={errors.schema_version_id}
                htmlFor="course-schema-version"
            >
                <select
                    id="course-schema-version"
                    value={data.schema_version_id}
                    onChange={(e) => setData('schema_version_id', e.target.value)}
                    className={inputClass}
                >
                    {versions.map((version) => (
                        <option key={version.id} value={version.id}>
                            {version.label}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-zinc-500 dark:text-zinc-400">
                    This cannot be changed later — the course tree is shaped by it.
                </p>
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Code" error={errors.code} htmlFor="course-code">
                    <input
                        id="course-code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        placeholder="ENG-10"
                        className={inputClass}
                    />
                </Field>

                <Field label="Language" error={errors.language} htmlFor="course-language">
                    <input
                        id="course-language"
                        value={data.language}
                        onChange={(e) => setData('language', e.target.value)}
                        className={inputClass}
                    />
                </Field>

                <Field label="Subject" error={errors.subject} htmlFor="course-subject">
                    <input
                        id="course-subject"
                        value={data.subject}
                        onChange={(e) => setData('subject', e.target.value)}
                        placeholder="English"
                        className={inputClass}
                    />
                </Field>

                <Field label="Grade band" error={errors.grade_band} htmlFor="course-grade-band">
                    <input
                        id="course-grade-band"
                        value={data.grade_band}
                        onChange={(e) => setData('grade_band', e.target.value)}
                        placeholder="Grade 10"
                        className={inputClass}
                    />
                </Field>
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Create course
                </button>
                <button type="button" onClick={onCancel} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}

const inputClass =
    'w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900';

function Field({
    label,
    error,
    htmlFor,
    children,
}: {
    label: string;
    error?: string;
    htmlFor: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <label className="block text-sm font-medium" htmlFor={htmlFor}>
                {label}
            </label>
            {children}
            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
