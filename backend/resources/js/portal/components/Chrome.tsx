import { useEffect, useState, type ReactNode } from 'react';
import { getCategories } from '../api';
import { useAuth } from '../lib/auth';
import { useAsync } from '../lib/useAsync';
import { useAuthModal } from './AuthModal';
import { Link } from '../router';

/** Header + footer around the reading pages (the Learn player is full-bleed). */
export function Chrome({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-full flex-col">
            <header className="sticky top-0 z-30 border-b border-[var(--line)] bg-[var(--paper)]">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
                    <Link href="/" className="flex items-center gap-2.5" aria-label="Samchita home">
                        <span className="grid h-8 w-8 place-items-center rounded-lg bg-[var(--accent)] font-display text-lg font-semibold text-[var(--on-accent)]">S</span>
                        <span className="font-display text-xl font-semibold tracking-tight">Samchita</span>
                    </Link>
                    <nav className="flex items-center gap-1 text-sm font-medium">
                        <Link href="/" className="hidden rounded-lg px-3 py-2 text-[var(--muted)] transition-colors hover:text-[var(--ink)] sm:block">Home</Link>
                        <CoursesMenu />
                        <Link href="/shorts" className="rounded-lg px-3 py-2 text-[var(--muted)] transition-colors hover:text-[var(--ink)]">Shorts</Link>
                        <AuthNav />
                    </nav>
                </div>
            </header>

            <VerifyBanner />

            <main className="flex-1">{children}</main>

            <footer className="mt-16 border-t border-[var(--line)] py-10">
                <div className="mx-auto flex max-w-6xl flex-col items-center gap-1.5 px-5 text-center text-sm text-[var(--muted)]">
                    <p className="font-display text-lg text-[var(--ink)]">Samchita</p>
                    <p>Learn anything, beautifully — free and open.</p>
                </div>
            </footer>
        </div>
    );
}

/** Header auth: a signed-in menu, or a "Sign in" button that opens the dialog. */
function AuthNav() {
    const { user, loading, logout } = useAuth();
    const { open } = useAuthModal();
    const [menu, setMenu] = useState(false);

    if (loading) return <span className="w-16" />;

    if (!user) {
        return (
            <button
                onClick={() => open('signin')}
                className="ml-1 rounded-full bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-[var(--on-accent)] transition hover:opacity-90"
            >
                Sign in
            </button>
        );
    }

    const initial = user.name.trim().charAt(0).toUpperCase() || '?';
    return (
        <div className="relative ml-1 flex items-center gap-1">
            <Link href="/my" className="rounded-lg px-3 py-2 text-[var(--muted)] transition-colors hover:text-[var(--ink)]">
                My learning
            </Link>
            <button
                onClick={() => setMenu((m) => !m)}
                className="grid h-9 w-9 place-items-center rounded-full bg-[var(--accent-soft)] font-semibold text-[var(--accent)]"
                aria-label="Account"
            >
                {initial}
            </button>
            {menu && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setMenu(false)} />
                    <div className="absolute right-0 z-20 mt-2 w-52 rounded-xl border border-[var(--line)] bg-[var(--card)] p-1.5 shadow-xl">
                        <div className="px-3 py-2">
                            <p className="truncate text-sm font-semibold">{user.name}</p>
                            <p className="truncate text-xs text-[var(--muted)]">{user.email}</p>
                        </div>
                        <button
                            onClick={() => {
                                setMenu(false);
                                void logout();
                            }}
                            className="w-full rounded-lg px-3 py-2 text-left text-sm text-[var(--muted)] hover:bg-[var(--paper)] hover:text-[var(--ink)]"
                        >
                            Sign out
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}

/** A gentle "verify your email" banner; also handles the ?verified=1 return. */
function VerifyBanner() {
    const { user, resendVerification, refresh } = useAuth();
    const [msg, setMsg] = useState('');

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('verified') === '1') {
            void refresh();
            window.history.replaceState({}, '', window.location.pathname);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (!user || user.email_verified !== false) return null;

    return (
        <div className="border-b border-amber-200 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/40">
            <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-5 py-2 text-sm text-amber-900 dark:text-amber-200">
                <span>Please verify your email to secure your account.</span>
                {msg ? (
                    <span className="font-medium">{msg}</span>
                ) : (
                    <button
                        onClick={async () => {
                            try {
                                setMsg(await resendVerification());
                            } catch {
                                setMsg('Could not send — try again shortly.');
                            }
                        }}
                        className="font-semibold underline underline-offset-2"
                    >
                        Resend email
                    </button>
                )}
            </div>
        </div>
    );
}

/** Top-bar "Courses" dropdown: All courses + a link per category. */
function CoursesMenu() {
    const [open, setOpen] = useState(false);
    const { data } = useAsync(getCategories, []);
    const categories = data ?? [];

    return (
        <div className="relative">
            <button
                onClick={() => setOpen((o) => !o)}
                className="flex items-center gap-1 rounded-lg px-3 py-2 text-[var(--muted)] transition-colors hover:text-[var(--ink)]"
                aria-haspopup="menu"
                aria-expanded={open}
            >
                Courses <span className="text-[10px]">▾</span>
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute left-0 z-20 mt-1 w-56 rounded-xl border border-[var(--line)] bg-[var(--card)] p-1.5 shadow-xl">
                        <Link
                            href="/courses"
                            onClick={() => setOpen(false)}
                            className="block rounded-lg px-3 py-2 text-sm hover:bg-[var(--paper)]"
                        >
                            All courses
                        </Link>
                        {categories.length > 0 && <div className="my-1 border-t border-[var(--line)]" />}
                        {categories.map((c) => (
                            <Link
                                key={c.value}
                                href={`/courses?category=${c.value}`}
                                onClick={() => setOpen(false)}
                                className="flex items-center justify-between rounded-lg px-3 py-2 text-sm hover:bg-[var(--paper)]"
                            >
                                <span>{c.label}</span>
                                <span className="text-xs text-[var(--muted)] tabular-nums">{c.count}</span>
                            </Link>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
