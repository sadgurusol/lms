import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

export default function SetPassword({ token, email }: { token: string; email: string }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/studio/set-password');
    }

    return (
        <>
            <Head title="Set your password" />

            <div className="flex min-h-full items-center justify-center px-6 py-16">
                <form onSubmit={submit} className="w-full max-w-sm space-y-5">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Set your password</h1>
                        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Choose a password to activate your studio account and sign in.
                        </p>
                    </div>

                    <Field label="Email" error={errors.email}>
                        <input
                            type="email"
                            autoComplete="username"
                            required
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                        />
                    </Field>

                    <Field label="New password" error={errors.password}>
                        <input
                            type="password"
                            autoComplete="new-password"
                            autoFocus
                            required
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                        />
                    </Field>

                    <Field label="Confirm password" error={undefined}>
                        <input
                            type="password"
                            autoComplete="new-password"
                            required
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                        />
                    </Field>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Set password & sign in
                    </button>
                </form>
            </div>
        </>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <label className="block text-sm font-medium">{label}</label>
            {children}
            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
