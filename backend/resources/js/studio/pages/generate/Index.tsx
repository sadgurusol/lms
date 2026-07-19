import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type SchemaOption = { id: string; name: string };

type Generation = {
    id: string;
    name: string;
    source_type: 'pdf' | 'brief';
    status: 'pending' | 'processing' | 'completed' | 'failed';
    error: string | null;
    course_id: string | null;
    can_retry: boolean;
    schema: string;
    created_at: string | null;
};

type Props = { generations: Generation[]; schemas: SchemaOption[] };

const STATUS_STYLE: Record<string, string> = {
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    processing: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    pending: 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
};

export default function GenerateIndex({ generations, schemas }: Props) {
    // Poll while anything is still running, so a finished draft shows up.
    const running = generations.some((g) => g.status === 'pending' || g.status === 'processing');
    useEffect(() => {
        if (!running) return;
        const t = setInterval(() => router.reload({ only: ['generations'] }), 4000);
        return () => clearInterval(t);
    }, [running]);

    return (
        <StudioLayout title="Generate a course">
            <Head title="Generate a course" />

            <p className="mb-6 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                Draft a course with AI — upload a textbook PDF, or describe the course and let it build
                from the subject. It's structured to the schema you pick and lands as a <strong>draft</strong>{' '}
                for you to review and publish. Generation runs in the background.
            </p>

            {schemas.length === 0 ? (
                <p className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    Publish a schema first — a course can only be generated against a published schema.
                </p>
            ) : (
                <GenerateForm schemas={schemas} />
            )}

            <h2 className="mb-3 mt-8 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Recent generations</h2>
            {generations.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">Nothing generated yet.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table className="w-full text-sm">
                        <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th className="px-4 py-2 font-medium">Course</th>
                                <th className="px-4 py-2 font-medium">Schema</th>
                                <th className="px-4 py-2 font-medium">Source</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 text-right font-medium">Result</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                            {generations.map((g) => (
                                <tr key={g.id}>
                                    <td className="px-4 py-3 font-medium">{g.name}</td>
                                    <td className="px-4 py-3 text-zinc-500 dark:text-zinc-400">{g.schema}</td>
                                    <td className="px-4 py-3 capitalize text-zinc-500 dark:text-zinc-400">{g.source_type}</td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLE[g.status]}`}>
                                            {g.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {g.status === 'completed' && g.course_id ? (
                                            <Link href={`/studio/courses/${g.course_id}`} className="text-indigo-600 hover:underline">
                                                Open draft
                                            </Link>
                                        ) : g.status === 'failed' ? (
                                            <span className="inline-flex items-center gap-2">
                                                <span className="text-xs text-red-600" title={g.error ?? ''}>
                                                    {g.error ? g.error.slice(0, 60) : 'Failed'}
                                                </span>
                                                {g.can_retry && <RetryButton id={g.id} />}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-zinc-400">working…</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </StudioLayout>
    );
}

function RetryButton({ id }: { id: string }) {
    const [busy, setBusy] = useState(false);
    return (
        <button
            type="button"
            disabled={busy}
            onClick={() =>
                router.post(
                    `/studio/generate/${id}/retry`,
                    {},
                    { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
                )
            }
            className="rounded border border-zinc-300 px-2 py-0.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
        >
            {busy ? 'Retrying…' : 'Retry'}
        </button>
    );
}

function GenerateForm({ schemas }: { schemas: SchemaOption[] }) {
    const [mode, setMode] = useState<'brief' | 'pdf'>('brief');
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        schema_version_id: string;
        source_type: 'brief' | 'pdf';
        brief: string;
        pdf: File | null;
    }>({
        name: '',
        schema_version_id: schemas[0]?.id ?? '',
        source_type: 'brief',
        brief: '',
        pdf: null,
    });

    function choose(next: 'brief' | 'pdf') {
        setMode(next);
        setData('source_type', next);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/generate', {
            forceFormData: true,
            onSuccess: () => reset('name', 'brief', 'pdf'),
        });
    }

    return (
        <form onSubmit={submit} className="max-w-2xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <label className="block text-sm font-medium" htmlFor="gen-name">Course name</label>
                    <input
                        id="gen-name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="NEET Biology — Class 11"
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    />
                    {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                </div>
                <div className="space-y-1.5">
                    <label className="block text-sm font-medium" htmlFor="gen-schema">Schema</label>
                    <select
                        id="gen-schema"
                        value={data.schema_version_id}
                        onChange={(e) => setData('schema_version_id', e.target.value)}
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        {schemas.map((s) => (
                            <option key={s.id} value={s.id}>{s.name}</option>
                        ))}
                    </select>
                    {errors.schema_version_id && <p className="text-sm text-red-600">{errors.schema_version_id}</p>}
                </div>
            </div>

            <div className="inline-flex rounded-md border border-zinc-300 p-0.5 text-sm dark:border-zinc-700">
                {(['brief', 'pdf'] as const).map((m) => (
                    <button
                        key={m}
                        type="button"
                        onClick={() => choose(m)}
                        className={`rounded px-3 py-1 font-medium ${
                            mode === m ? 'bg-indigo-600 text-white' : 'text-zinc-600 dark:text-zinc-300'
                        }`}
                    >
                        {m === 'brief' ? 'From a brief' : 'From a PDF'}
                    </button>
                ))}
            </div>

            {mode === 'brief' ? (
                <div className="space-y-1.5">
                    <label className="block text-sm font-medium" htmlFor="gen-brief">Brief</label>
                    <textarea
                        id="gen-brief"
                        rows={5}
                        value={data.brief}
                        onChange={(e) => setData('brief', e.target.value)}
                        placeholder="Describe the course: subject, level, syllabus/exam board, what it should cover, and any study-plan or schedule you want included."
                        className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    />
                    {errors.brief && <p className="text-sm text-red-600">{errors.brief}</p>}
                </div>
            ) : (
                <div className="space-y-1.5">
                    <label className="block text-sm font-medium" htmlFor="gen-pdf">Textbook PDF</label>
                    <input
                        id="gen-pdf"
                        type="file"
                        accept="application/pdf"
                        onChange={(e) => setData('pdf', e.target.files?.[0] ?? null)}
                        className="w-full text-sm"
                    />
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">Up to 30 MB. Large textbooks work best chapter by chapter.</p>
                    {errors.pdf && <p className="text-sm text-red-600">{errors.pdf}</p>}
                </div>
            )}

            <button
                type="submit"
                disabled={processing}
                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                {processing ? 'Starting…' : 'Generate course'}
            </button>
        </form>
    );
}
