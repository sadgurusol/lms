import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import { ConfirmProvider } from '@/studio/components/ConfirmDialog';

/**
 * The studio entrypoint.
 *
 * Pages are code-split by Vite's glob import, so opening the schema builder does
 * not download the rich-text editor.
 */
const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

void createInertiaApp({
    title: (title) => (title ? `${title} · Studio` : 'Studio'),

    resolve: async (name) => {
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return (await page()).default;
    },

    setup({ el, App, props }) {
        createRoot(el).render(
            <ConfirmProvider>
                <App {...props} />
            </ConfirmProvider>,
        );
    },

    progress: { color: '#4f46e5' },
});
