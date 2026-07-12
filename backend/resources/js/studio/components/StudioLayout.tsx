import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { Auth, FlashMessages } from '@/studio/types/global';

type NavItem = {
    label: string;
    href: string;
    /** The permission the *server* says this user holds. Never re-derived here. */
    permission?: string;
};

const NAV: NavItem[] = [
    { label: 'Dashboard', href: '/studio' },
    { label: 'Schemas', href: '/studio/schemas', permission: 'schema.view' },
    { label: 'Courses', href: '/studio/courses', permission: 'course.view.granted' },
    { label: 'Question bank', href: '/studio/questions', permission: 'question.manage' },
    { label: 'Clients', href: '/ops/clients', permission: 'client.view' },
    { label: 'Products', href: '/ops/products', permission: 'product.view' },
];

export default function StudioLayout({ title, children }: { title: string; children: ReactNode }) {
    // `url` lives on the page object, not on props. Reading it off props
    // typechecks after a cast and is silently undefined at runtime.
    const page = usePage<{ auth: Auth; flash: FlashMessages }>();
    const { auth, flash } = page.props;
    const url = page.url;

    const visible = NAV.filter((item) => !item.permission || auth.can[item.permission]);

    return (
        <div className="flex min-h-full flex-col">
            <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div className="mx-auto flex max-w-7xl items-center gap-6 px-6 py-3">
                    <span className="font-semibold tracking-tight">LMS Studio</span>

                    <nav className="flex gap-1" aria-label="Main">
                        {visible.map((item) => {
                            const active = url === item.href || url.startsWith(`${item.href}/`);

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={[
                                        'rounded-md px-3 py-1.5 text-sm',
                                        active
                                            ? 'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-800/50',
                                    ].join(' ')}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="ml-auto flex items-center gap-3 text-sm">
                        <span className="text-zinc-500 dark:text-zinc-400">{auth.user?.name}</span>
                        <button
                            type="button"
                            onClick={() => router.post('/studio/logout')}
                            className="rounded-md px-2 py-1 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        >
                            Sign out
                        </button>
                    </div>
                </div>
            </header>

            <main className="mx-auto w-full max-w-7xl flex-1 px-6 py-8">
                <h1 className="mb-6 text-2xl font-semibold tracking-tight">{title}</h1>

                {/* aria-live so a screen reader hears the result of a publish. */}
                <div aria-live="polite">
                    {flash.success && <Banner tone="success">{flash.success}</Banner>}
                    {flash.error && <Banner tone="error">{flash.error}</Banner>}
                </div>

                {children}
            </main>
        </div>
    );
}

function Banner({ tone, children }: { tone: 'success' | 'error'; children: ReactNode }) {
    const styles =
        tone === 'success'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
            : 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100';

    return <div className={`mb-6 rounded-md border px-4 py-3 text-sm ${styles}`}>{children}</div>;
}
