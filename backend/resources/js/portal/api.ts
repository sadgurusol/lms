import type { Fragment } from '@/studio/components/AnimatedRevealPreview';
import type { Block } from '@/studio/components/BlockView';

export type CourseCardData = {
    slug: string;
    title: string;
    subject: string | null;
    category?: string | null;
    grade_band: string | null;
    language: string;
    lessons?: number;
};

export type CategoryFacet = { value: string; label: string; count: number };

export type Catalog = {
    data: CourseCardData[];
    categories: CategoryFacet[];
    subjects: string[];
    grade_bands: string[];
};

export type OutlineNode = {
    id: string;
    title: string;
    label: string | null;
    number: string | null;
    summary: string | null;
    has_content: boolean;
    locked: boolean;
    children: OutlineNode[];
};

export type Landing = {
    course: CourseCardData;
    published_at: string | null;
    outline: OutlineNode[];
    counts: { lessons: number };
    access: { free_preview: number | null };
};

export type ContentNode = {
    id: string;
    title: string;
    label?: string | null;
    number?: string | null;
    summary?: string | null;
    blocks?: Block[];
    locked?: boolean;
    children?: ContentNode[];
};

export type Content = {
    publication: { id: string; number: number; published_at: string | null };
    course: CourseCardData;
    tree: ContentNode[];
    access: { free_preview: number | null; locked_lessons: number };
};

const base = '/api/v1/portal';

async function json<T>(url: string): Promise<T> {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) {
        throw new Error(res.status === 404 ? 'not_found' : 'request_failed');
    }
    return (await res.json()) as T;
}

export const getCatalog = () => json<Catalog>(`${base}/courses`);
export const getCategories = () => json<{ categories: CategoryFacet[] }>(`${base}/categories`).then((d) => d.categories);

export type Short = {
    node_id: string;
    course_slug: string;
    course_title: string;
    subject: string | null;
    title: string;
    fragments: Fragment[];
    views: number;
};

export const getShorts = (focus?: string) =>
    json<{ data: Short[] }>(`${base}/shorts${focus ? `?focus=${encodeURIComponent(focus)}` : ''}`).then((d) => d.data);

/** Best-effort anonymous view capture (fire-and-forget). */
export function recordShortView(slug: string, nodeId: string): void {
    void fetch(`${base}/shorts/view`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug, node_id: nodeId }),
        credentials: 'same-origin',
        keepalive: true,
    }).catch(() => {});
}
export const getCourse = (slug: string) => json<Landing>(`${base}/courses/${encodeURIComponent(slug)}`);
export const getContent = (slug: string) => json<Content>(`${base}/courses/${encodeURIComponent(slug)}/content`);
