import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/login');
    }

    return (
        <>
            <Head title="Sign in" />

            <div className="flex min-h-full items-center justify-center px-6 py-16">
                <form onSubmit={submit} className="w-full max-w-sm space-y-5">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Sign in to the studio</h1>
                        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            For content authors, reviewers and admins.
                        </p>
                    </div>

                    <Field label="Email" error={errors.email}>
                        <input
                            type="email"
                            autoComplete="username"
                            autoFocus
                            required
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                        />
                    </Field>

                    <Field label="Password" error={errors.password}>
                        <input
                            type="password"
                            autoComplete="current-password"
                            required
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                        />
                    </Field>

                    <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                        />
                        Remember me
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
            </div>
        </>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
            <label className="block text-sm font-medium">{label}</label>
            {children}
            {/* role=alert so the error is announced, not just coloured red. */}
            {error && (
                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </div>
    );
}
