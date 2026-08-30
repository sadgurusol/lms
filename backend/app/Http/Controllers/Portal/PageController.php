<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Portal\CourseGate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Serves the portal SPA shell with per-route SEO/social meta (so a shared course
 * link renders a real title + description and crawlers see them), plus the
 * sitemap and robots for indexing.
 */
class PageController extends Controller
{
    public function __construct(private readonly CourseGate $gate) {}

    public function home(): View
    {
        return $this->page();
    }

    public function catalog(): View
    {
        return $this->page(
            'Courses',
            'Browse free, interactive lessons across every subject on Samchita.',
        );
    }

    public function course(string $slug): View
    {
        $course = $this->gate->accessibleQuery()
            ->with(['latestPublication' => fn ($q) => $q->select('id', 'lessons_count')])
            ->where(function ($q) use ($slug) {
                $q->where('slug', $slug);
                if (Str::isUuid($slug)) {
                    $q->orWhere('id', $slug);
                }
            })
            ->first();

        if ($course === null) {
            return $this->page();
        }

        $n = (int) ($course->latestPublication->lessons_count ?? 0);
        $lessons = $n.' free interactive lesson'.($n === 1 ? '' : 's');
        $description = "Learn {$course->title}".($course->subject ? " ({$course->subject})" : '').
            " — {$lessons} with animated explanations and narration.";

        return $this->page($course->title, $description, $this->courseJsonLd($course, $description));
    }

    /** The player route shares the course's meta. */
    public function learn(string $slug): View
    {
        return $this->course($slug);
    }

    /** An XML sitemap of the home, catalogue, and every public course landing. */
    public function sitemap(): Response
    {
        $urls = [url('/'), url('/courses')];
        foreach ($this->gate->listable()->orderBy('title')->get(['id', 'slug']) as $course) {
            $urls[] = url('/courses/'.($course->slug ?: $course->id));
        }

        $body = implode("\n", array_map(
            fn (string $u) => '  <url><loc>'.e($u).'</loc></url>',
            $urls,
        ));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$body."\n"
            .'</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /studio',
            'Disallow: /api',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * @param  array<string, mixed>|null  $structured  JSON-LD for this page, if any
     */
    private function page(?string $title = null, ?string $description = null, ?array $structured = null): View
    {
        return view('portal', [
            'metaTitle' => $title ? "{$title} · Samchita" : 'Samchita — Learn',
            'metaDescription' => $description
                ?: 'Free, interactive lessons with animated explanations and narration. Open any course and start learning.',
            'metaUrl' => url()->current(),
            'structured' => $structured,
        ]);
    }

    /** @return array<string, mixed> */
    private function courseJsonLd(Course $course, string $description): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Course',
            'name' => $course->title,
            'description' => $description,
            'url' => url('/courses/'.($course->slug ?: $course->id)),
            'inLanguage' => $course->language,
            'educationalLevel' => $course->grade_band,
            'about' => $course->subject,
            'isAccessibleForFree' => true,
            'provider' => [
                '@type' => 'Organization',
                'name' => 'Samchita',
                'url' => url('/'),
            ],
        ], fn ($v) => $v !== null && $v !== '');
    }
}
