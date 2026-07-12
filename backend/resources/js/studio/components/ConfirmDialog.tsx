import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';

/**
 * A promise-based confirmation dialog, so a click handler can `await confirm(…)`
 * in place of the browser's `window.confirm`. One dialog is mounted at the app
 * root (see ConfirmProvider); calling `useConfirm()` opens it and resolves to
 * true/false when the user chooses.
 */

export type ConfirmOptions = {
    title?: string;
    message: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    /** A destructive action — styles the confirm button red. */
    danger?: boolean;
};

type Pending = ConfirmOptions & { resolve: (result: boolean) => void };

const ConfirmContext = createContext<((options: ConfirmOptions) => Promise<boolean>) | null>(null);

export function useConfirm(): (options: ConfirmOptions) => Promise<boolean> {
    const confirm = useContext(ConfirmContext);
    if (!confirm) {
        throw new Error('useConfirm must be used within a <ConfirmProvider>.');
    }
    return confirm;
}

export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [pending, setPending] = useState<Pending | null>(null);

    const confirm = useCallback(
        (options: ConfirmOptions) =>
            new Promise<boolean>((resolve) => setPending({ ...options, resolve })),
        [],
    );

    const close = useCallback((result: boolean) => {
        setPending((current) => {
            current?.resolve(result);
            return null;
        });
    }, []);

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            {pending && <ConfirmDialog pending={pending} onClose={close} />}
        </ConfirmContext.Provider>
    );
}

function ConfirmDialog({ pending, onClose }: { pending: Pending; onClose: (result: boolean) => void }) {
    const confirmRef = useRef<HTMLButtonElement>(null);

    const {
        title = 'Are you sure?',
        message,
        confirmLabel = 'Confirm',
        cancelLabel = 'Cancel',
        danger = false,
    } = pending;

    useEffect(() => {
        confirmRef.current?.focus();
        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') onClose(false);
            if (event.key === 'Enter') onClose(true);
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const confirmClass = danger
        ? 'bg-red-600 hover:bg-red-500 focus-visible:outline-red-600'
        : 'bg-indigo-600 hover:bg-indigo-500 focus-visible:outline-indigo-600';

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirm-title"
        >
            <div
                className="absolute inset-0 bg-black/50 backdrop-blur-[1px]"
                onClick={() => onClose(false)}
                aria-hidden
            />
            <div className="relative w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <h2 id="confirm-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                    {title}
                </h2>
                <div className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{message}</div>
                <div className="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={() => onClose(false)}
                        className="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        ref={confirmRef}
                        type="button"
                        onClick={() => onClose(true)}
                        className={`rounded-md px-3 py-2 text-sm font-medium text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${confirmClass}`}
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
