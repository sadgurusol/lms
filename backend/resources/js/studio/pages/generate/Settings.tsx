import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type Props = {
    outlineInstructions: string;
    contentInstructions: string;
    baseOutlinePrompt: string;
    baseContentPrompt: string;
};

export default function GenerateSettings({
    outlineInstructions,
    contentInstructions,
    baseOutlinePrompt,
    baseContentPrompt,
}: Props) {
    const { data, setData, post, processing, recentlySuccessful } = useForm({
        outlineInstructions,
        contentInstructions,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/generate/settings', { preserveScroll: true });
    }

    return (
        <StudioLayout title="Generation settings">
            <Head title="Generation settings" />

            <div className="mb-6 flex items-center justify-between">
                <p className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    Add your own guidance to steer how courses are generated — house style, depth, tone,
                    how to handle figures, and so on. It's appended to the built-in prompts below (which
                    hold the parts the machine relies on, so they stay fixed).
                </p>
                <Link href="/studio/generate" className="shrink-0 text-sm text-indigo-600 hover:underline">
                    ← Back to Generate
                </Link>
            </div>

            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <Section
                    label="Content guidance"
                    hint="Applied when writing each topic's teaching text — the best place to control figures, examples, depth, and tone."
                    value={data.contentInstructions}
                    onChange={(v) => setData('contentInstructions', v)}
                    basePrompt={baseContentPrompt}
                    placeholder={
                        'e.g. Where a diagram would help (a shape, a graph, a process), describe it in enough ' +
                        'detail that a learner could sketch it. Prefer worked numerical examples for maths topics.'
                    }
                />

                <Section
                    label="Structure guidance"
                    hint="Applied when planning the course outline (sections and titles)."
                    value={data.outlineInstructions}
                    onChange={(v) => setData('outlineInstructions', v)}
                    basePrompt={baseOutlinePrompt}
                    placeholder="e.g. Order chapters to build from fundamentals to advanced. Add a 'Practice' topic at the end of each chapter."
                />

                <div className="flex items-center gap-3">
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {processing ? 'Saving…' : 'Save settings'}
                    </button>
                    {recentlySuccessful && <span className="text-sm text-emerald-600">Saved.</span>}
                </div>
            </form>
        </StudioLayout>
    );
}

function Section({
    label,
    hint,
    value,
    onChange,
    basePrompt,
    placeholder,
}: {
    label: string;
    hint: string;
    value: string;
    onChange: (v: string) => void;
    basePrompt: string;
    placeholder: string;
}) {
    return (
        <div className="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
            <label className="block text-sm font-semibold">{label}</label>
            <p className="text-xs text-zinc-500 dark:text-zinc-400">{hint}</p>
            <textarea
                rows={5}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-md border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-700 dark:bg-zinc-900"
            />
            <details className="text-xs text-zinc-500 dark:text-zinc-400">
                <summary className="cursor-pointer select-none">Show the built-in base prompt</summary>
                <pre className="mt-2 overflow-x-auto whitespace-pre-wrap rounded-md bg-zinc-50 p-3 text-[11px] leading-relaxed dark:bg-zinc-900">
                    {basePrompt}
                </pre>
            </details>
        </div>
    );
}
