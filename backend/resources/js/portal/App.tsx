import { Chrome } from './components/Chrome';
import { Link, useRouter } from './router';
import Home from './pages/Home';
import Catalog from './pages/Catalog';
import Course from './pages/Course';
import Learn from './pages/Learn';
import My from './pages/My';
import ResetPassword from './pages/ResetPassword';

export default function App() {
    const { path } = useRouter();

    // The Learn player is full-bleed (its own dark chrome), so it renders outside Chrome.
    const learn = path.match(/^\/courses\/([^/]+)\/learn$/);
    if (learn) return <Learn slug={decodeURIComponent(learn[1] ?? '')} />;

    let page = <NotFound />;
    if (path === '/') page = <Home />;
    else if (path === '/courses') page = <Catalog />;
    else if (path === '/my') page = <My />;
    else if (path === '/reset-password') page = <ResetPassword />;
    else {
        const course = path.match(/^\/courses\/([^/]+)$/);
        if (course) page = <Course slug={decodeURIComponent(course[1] ?? '')} />;
    }

    return <Chrome>{page}</Chrome>;
}

function NotFound() {
    return (
        <div className="mx-auto flex max-w-2xl flex-col items-center px-5 py-32 text-center">
            <p className="font-display text-6xl font-semibold">404</p>
            <p className="mt-3 text-[var(--muted)]">We couldn’t find that page.</p>
            <Link href="/courses" className="mt-6 rounded-full bg-[var(--accent)] px-5 py-2.5 text-sm font-semibold text-[var(--on-accent)]">
                Browse courses
            </Link>
        </div>
    );
}
