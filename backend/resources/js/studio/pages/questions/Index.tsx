import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import StudioLayout from '@/studio/components/StudioLayout';

type Bank = {
    id: string;
    name: string;
    question_count: number;
    course: { id: string; title: string } | null;
};

type Props = {
    banks: Bank[];
    courses: { id: string; title: string }[];
    can: { create: boolean };
};

export default function QuestionsIndex({ banks, courses, can }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <StudioLayout title="Question banks">
            <Head title="Question banks" />

            <p className="mb-6 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                A question bank holds reusable questions. A <strong>global</strong> bank is shared
                across courses; a <strong>course</strong> bank belongs to one course and is editable
                only by its authors.
            </p>

            {can.create && !creating && (
                <button
                    type="button"
                    onClick={() => setCreating(true)}
                    className="mb-6 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                >
                    New bank
                </button>
            )}

            {creating && <CreateForm courses={courses} onCancel={() => setCreating(false)} />}

            {banks.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">No question banks yet.</p>
            ) : (
                <ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    {banks.map((bank) => (
                        <li key={bank.id} className="flex items-center gap-4 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <Link
                                    href={`/studio/question-banks/${bank.id}`}
                                    className="font-medium hover:text-indigo-600 hover:underline dark:hover:text-indigo-400"
                                >
                                    {bank.name}
                                </Link>
                                <p className="text-sm text-zinc-500 dark:text-zinc-400">
                                    {bank.course ? `Course: ${bank.course.title}` : 'Global'} ·{' '}
                                    {bank.question_count}{' '}
                                    {bank.question_count === 1 ? 'question' : 'questions'}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </StudioLayout>
    );
}

function CreateForm({
    courses,
    onCancel,
}: {
    courses: { id: string; title: string }[];
    onCancel: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', course_id: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/questions', { onSuccess: () => reset() });
    }

    return (
        <form
            onSubmit={submit}
            className="mb-6 max-w-xl space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="bank-name">
                    Name
                </label>
                <input
                    id="bank-name"
                    autoFocus
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="Grade 10 English — Grammar"
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                />
                {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="block text-sm font-medium" htmlFor="bank-course">
                    Scope
                </label>
                <select
                    id="bank-course"
                    value={data.course_id}
                    onChange={(e) => setData('course_id', e.target.value)}
                    className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <option value="">Global (shared across courses)</option>
                    {courses.map((c) => (
                        <option key={c.id} value={c.id}>
                            Course: {c.title}
                        </option>
                    ))}
                </select>
                {errors.course_id && <p className="text-sm text-red-600">{errors.course_id}</p>}
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    Create bank
                </button>
                <button type="button" onClick={onCancel} className="rounded-md px-3 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}
