import { useEffect } from 'react';

/** Keep the document title in step as the SPA navigates client-side. */
export function usePageTitle(title?: string | null) {
    useEffect(() => {
        document.title = title ? `${title} · Samchita` : 'Samchita — Learn';
    }, [title]);
}
