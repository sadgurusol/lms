import { useState, type FormEvent } from 'react';
import { useAuth } from '../lib/auth';
import { usePageTitle } from '../lib/usePageTitle';
import { Link } from '../router';

export default function ResetPassword() {
    usePageTitle('Reset password');
    const { resetPassword } = useAuth();
    const params = new URLSearchParams(window.location.search);
    const token = params.get('token') ?? '';
    const email = params.get('email') ?? '';

    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);

    const input =
        'mt-1 w-full rounded-lg border border-[var(--line)] bg-[var(--card)] px-3 py-2.5 text-sm outline-none focus:border-[var(--accent)]';

    async function submit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            await resetPassword(token, email, password, confirm);
            setDone(true);
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setBusy(false);
        }
    }

    if (!token || !email) {
        return (
            <div className="mx-auto max-w-sm px-5 py-24 text-center">
                <h1 className="font-display text-2xl font-semibold">Invalid reset link</h1>
                <p className="mt-2 text-sm text-[var(--muted)]">This link is missing information or has expired.</p>
                <Link href="/" className="mt-5 inline-block text-sm font-semibold text-[var(--accent)]">← Back to portal</Link>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-sm px-5 py-20">
            <h1 className="font-display text-3xl font-semibold">Set a new password</h1>
            {done ? (
                <div className="mt-6">
                    <p className="rounded-lg bg-[var(--accent-soft)] p-3 text-sm text-[var(--accent)]">
                        Password updated. You can sign in with your new password now.
                    </p>
                    <Link href="/" className="mt-5 inline-block rounded-full bg-[var(--accent)] px-6 py-2.5 text-sm font-semibold text-[var(--on-accent)]">
                        Go to the portal
                    </Link>
                </div>
            ) : (
                <form onSubmit={submit} className="mt-6 space-y-3">
                    <p className="text-sm text-[var(--muted)]">for {email}</p>
                    <label className="block text-sm font-medium">
                        New password
                        <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoFocus className={input} />
                    </label>
                    <label className="block text-sm font-medium">
                        Confirm password
                        <input type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} className={input} />
                    </label>
                    {error && <p className="text-sm text-red-500">{error}</p>}
                    <button
                        type="submit"
                        disabled={busy}
                        className="w-full rounded-full bg-[var(--accent)] px-5 py-2.5 text-sm font-semibold text-[var(--on-accent)] transition hover:opacity-90 disabled:opacity-50"
                    >
                        {busy ? 'Updating…' : 'Update password'}
                    </button>
                </form>
            )}
        </div>
    );
}
