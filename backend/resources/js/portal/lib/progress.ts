import { apiGet, apiPost } from './http';

export type MyCourse = {
    slug: string;
    title: string;
    subject: string | null;
    grade_band: string | null;
    language: string;
    lessons: number;
    done: number;
};

/** Completed lesson node-ids for the signed-in learner on a course. */
export const fetchServerProgress = (slug: string) =>
    apiGet<{ completed: string[] }>(`/portal/courses/${encodeURIComponent(slug)}/progress`).then((d) => d.completed ?? []);

export const recordCompletion = (slug: string, nodeId: string) =>
    apiPost(`/portal/courses/${encodeURIComponent(slug)}/progress`, { node_id: nodeId, state: 'completed' });

export const enrollCourse = (slug: string) => apiPost(`/portal/courses/${encodeURIComponent(slug)}/enroll`);

export const fetchMyCourses = () => apiGet<{ data: MyCourse[] }>('/portal/me/courses').then((d) => d.data ?? []);
