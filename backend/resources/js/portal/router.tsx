import { createContext, useContext, useEffect, useState, type MouseEvent, type ReactNode } from 'react';

type RouterValue = { path: string; query: URLSearchParams; navigate: (to: string) => void };
const RouterCtx = createContext<RouterValue>({ path: '/', query: new URLSearchParams(), navigate: () => {} });

export const useRouter = () => useContext(RouterCtx);

const here = () => window.location.pathname + window.location.search;

/** A tiny history-API router — enough for the portal's routes, no dependency. */
export function Router({ children }: { children: ReactNode }) {
    const [loc, setLoc] = useState(here);

    useEffect(() => {
        const onPop = () => setLoc(here());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const navigate = (to: string) => {
        if (to === here()) return;
        window.history.pushState({}, '', to);
        setLoc(to);
        window.scrollTo({ top: 0 });
    };

    const qIndex = loc.indexOf('?');
    const path = qIndex >= 0 ? loc.slice(0, qIndex) : loc;
    const query = new URLSearchParams(qIndex >= 0 ? loc.slice(qIndex) : '');

    return <RouterCtx.Provider value={{ path, query, navigate }}>{children}</RouterCtx.Provider>;
}

/** An <a> that routes client-side (but honours modifier-clicks / new-tab). */
export function Link({
    href,
    className,
    children,
    onClick: onClickProp,
    'aria-label': ariaLabel,
}: {
    href: string;
    className?: string;
    children: ReactNode;
    onClick?: () => void;
    'aria-label'?: string;
}) {
    const { navigate } = useRouter();
    const onClick = (e: MouseEvent) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        e.preventDefault();
        onClickProp?.();
        navigate(href);
    };
    return (
        <a href={href} onClick={onClick} className={className} aria-label={ariaLabel}>
            {children}
        </a>
    );
}
