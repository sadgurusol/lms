import { createContext, useContext, useState, type FormEvent, type ReactNode } from 'react';
import { useAuth } from '../lib/auth';

type Mode = 'signin' | 'signup' | 'forgot';
type AuthModalValue = { open: (mode?: Mode) => void };
const Ctx = createContext<AuthModalValue>({ open: () => {} });
export const useAuthModal = () => useContext(Ctx);

/** Provides `useAuthModal().open()` and renders the sign-in / sign-up / reset dialog. */
export function AuthModalProvider({ children }: { children: ReactNode }) {
    const [mode, setMode] = useState<Mode | null>(null);

    return (
        <Ctx.Provider value={{ open: (m = 'signin') => setMode(m) }}>
            {children}
            {mode && <AuthDialog mode={mode} setMode={setMode} onClose={() => setMode(null)} />}
        </Ctx.Provider>
    );
}

function AuthDialog({ mode, setMode, onClose }: { mode: Mode; setMode: (m: Mode) => void; onClose: () => void }) {
    const { login, register, forgotPassword } = useAuth();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [sent, setSent] = useState('');

    async function submit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            if (mode === 'signin') {
                await login(email, password);
                onClose();
            } else if (mode === 'signup') {
                await register(name, email, password, confirm);
                onClose();
            } else {
                setSent(await forgotPassword(email));
            }
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setBusy(false);
        }
    }

    const input =
        'mt-1 w-full rounded-lg border border-[var(--line)] bg-[var(--paper)] px-3 py-2.5 text-sm outline-none focus:border-[var(--accent)]';

    const title = mode === 'signin' ? 'Welcome back' : mode === 'signup' ? 'Create your account' : 'Reset your password';
    const subtitle =
        mode === 'signin'
            ? 'Sign in to save your progress across devices.'
            : mode === 'signup'
              ? 'Free — save your progress and pick up on any device.'
              : 'Enter your email and we’ll send you a reset link.';

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
            <div className="w-full max-w-sm rounded-2xl bg-[var(--card)] p-7 shadow-2xl" onClick={(e) => e.stopPropagation()}>
                <h2 className="font-display text-2xl font-semibold">{title}</h2>
                <p className="mt-1 text-sm text-[var(--muted)]">{subtitle}</p>

                {sent ? (
                    <div className="mt-5">
                        <p className="rounded-lg bg-[var(--accent-soft)] p-3 text-sm text-[var(--accent)]">{sent}</p>
                        <button onClick={() => setMode('signin')} className="mt-4 text-sm font-semibold text-[var(--accent)]">
                            ← Back to sign in
                        </button>
                    </div>
                ) : (
                    <>
                        <form onSubmit={submit} className="mt-5 space-y-3">
                            {mode === 'signup' && (
                                <label className="block text-sm font-medium">
                                    Name
                                    <input value={name} onChange={(e) => setName(e.target.value)} autoFocus className={input} />
                                </label>
                            )}
                            <label className="block text-sm font-medium">
                                Email
                                <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoFocus={mode !== 'signup'} className={input} />
                            </label>
                            {mode !== 'forgot' && (
                                <label className="block text-sm font-medium">
                                    Password
                                    <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className={input} />
                                </label>
                            )}
                            {mode === 'signup' && (
                                <label className="block text-sm font-medium">
                                    Confirm password
                                    <input type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} className={input} />
                                </label>
                            )}

                            {mode === 'signin' && (
                                <button type="button" onClick={() => setMode('forgot')} className="text-xs font-semibold text-[var(--accent)]">
                                    Forgot password?
                                </button>
                            )}

                            {error && <p className="text-sm text-red-500">{error}</p>}

                            <button
                                type="submit"
                                disabled={busy}
                                className="w-full rounded-full bg-[var(--accent)] px-5 py-2.5 text-sm font-semibold text-[var(--on-accent)] transition hover:opacity-90 disabled:opacity-50"
                            >
                                {busy ? 'Please wait…' : mode === 'signin' ? 'Sign in' : mode === 'signup' ? 'Create account' : 'Send reset link'}
                            </button>
                        </form>

                        <p className="mt-4 text-center text-sm text-[var(--muted)]">
                            {mode === 'signup' ? (
                                <>
                                    Already have an account?{' '}
                                    <button onClick={() => setMode('signin')} className="font-semibold text-[var(--accent)]">Sign in</button>
                                </>
                            ) : (
                                <>
                                    New here?{' '}
                                    <button onClick={() => setMode('signup')} className="font-semibold text-[var(--accent)]">Create an account</button>
                                </>
                            )}
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}
