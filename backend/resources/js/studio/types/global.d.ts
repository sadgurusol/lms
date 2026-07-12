/**
 * Props Inertia shares with every page (HandleInertiaRequests::share).
 *
 * `can` is the server's answer to "may this user do X". The client renders what
 * the server says it may render; it never re-derives authorization. There is one
 * authorization source and it is CoursePolicy (docs/13 §2).
 */
export type Auth = {
    user: {
        id: string;
        name: string;
        email: string | null;
        roles: string[];
    } | null;
    can: Record<string, boolean>;
};

export type FlashMessages = {
    success?: string;
    error?: string;
    /** A one-time secret (e.g. a rotated webhook key), shown once then gone. */
    secret?: string;
};

declare module '@inertiajs/core' {
    interface PageProps {
        auth: Auth;
        flash: FlashMessages;
    }
}

export {};
