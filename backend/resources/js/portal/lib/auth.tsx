import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { apiGet, apiPost } from './http';

export type User = { id: string; name: string; email: string; email_verified?: boolean };

type AuthValue = {
    user: User | null;
    loading: boolean;
    login: (email: string, password: string) => Promise<void>;
    register: (name: string, email: string, password: string, passwordConfirmation: string) => Promise<void>;
    logout: () => Promise<void>;
    forgotPassword: (email: string) => Promise<string>;
    resetPassword: (token: string, email: string, password: string, passwordConfirmation: string) => Promise<string>;
    resendVerification: () => Promise<string>;
    refresh: () => Promise<void>;
};

const AuthCtx = createContext<AuthValue | null>(null);

export function useAuth(): AuthValue {
    const ctx = useContext(AuthCtx);
    if (!ctx) throw new Error('useAuth must be used within AuthProvider');
    return ctx;
}

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = async () => {
        try {
            const d = await apiGet<{ user: User | null }>('/portal/auth/me');
            setUser(d.user ?? null);
        } catch {
            setUser(null);
        }
    };

    useEffect(() => {
        void refresh().finally(() => setLoading(false));
    }, []);

    const value: AuthValue = {
        user,
        loading,
        login: async (email, password) => setUser((await apiPost<{ user: User }>('/portal/auth/login', { email, password })).user),
        register: async (name, email, password, passwordConfirmation) =>
            setUser(
                (await apiPost<{ user: User }>('/portal/auth/register', { name, email, password, password_confirmation: passwordConfirmation }))
                    .user,
            ),
        logout: async () => {
            await apiPost('/portal/auth/logout');
            setUser(null);
        },
        forgotPassword: async (email) =>
            (await apiPost<{ message: string }>('/portal/auth/forgot-password', { email })).message,
        resetPassword: async (token, email, password, passwordConfirmation) =>
            (await apiPost<{ message: string }>('/portal/auth/reset-password', {
                token,
                email,
                password,
                password_confirmation: passwordConfirmation,
            })).message,
        resendVerification: async () => (await apiPost<{ message: string }>('/portal/auth/verify/resend')).message,
        refresh,
    };

    return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}
