import { Head, Link } from '@inertiajs/react';
import StudioLayout from '@/studio/components/StudioLayout';

type Stats = {
    schemas: number;
    courses: number;
    awaiting_review: number;
    published: number;
};

export default function Dashboard({ stats }: { stats: Stats }) {
    return (
        <StudioLayout title="Dashboard">
            <Head title="Dashboard" />

            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Stat label="Schemas" value={stats.schemas} href="/studio/schemas" />
                <Stat label="Courses" value={stats.courses} />
                <Stat label="Awaiting review" value={stats.awaiting_review} />
                <Stat label="Published" value={stats.published} />
            </dl>

            {stats.schemas === 0 && (
                <p className="mt-8 text-sm text-zinc-600 dark:text-zinc-400">
                    Nothing exists yet. A course cannot be created without a schema, so{' '}
                    <Link href="/studio/schemas" className="font-medium text-indigo-600 underline">
                        define one first
                    </Link>
                    .
                </p>
            )}
        </StudioLayout>
    );
}

function Stat({ label, value, href }: { label: string; value: number; href?: string }) {
    const card = (
        <div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
            <dt className="text-sm text-zinc-500 dark:text-zinc-400">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums">{value}</dd>
        </div>
    );

    return href ? <Link href={href}>{card}</Link> : card;
}
