/** Same-origin session requests for the portal (CSRF via the XSRF-TOKEN cookie). */

export function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m?.[1] ? decodeURIComponent(m[1]) : '';
}

export async function apiGet<T>(url: string): Promise<T> {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) throw new Error(String(res.status));
    return (await res.json()) as T;
}

export async function apiPost<T = unknown>(url: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body === undefined ? '{}' : JSON.stringify(body),
        credentials: 'same-origin',
    });
    const data = (await res.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        throw new Error(first ?? data.message ?? 'Request failed');
    }
    return data as T;
}
