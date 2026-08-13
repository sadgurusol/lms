<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The read-only catalogue a B2B client browses before mapping a course into
 * their own product (docs/14 WS4). Authenticated by AuthenticateClient — the
 * client is on $request->attributes 'client'.
 */
class PartnerCatalogController extends Controller
{
    /** Published courses, flagged with whether this client is entitled to each. */
    public function courses(Request $request): JsonResponse
    {
        $client = $request->attributes->get('client');

        $productIds = ClientEntitlement::where('client_id', $client->id)
            ->where('status', 'active')
            ->pluck('product_id');

        $entitledCourseIds = DB::table('product_courses')
            ->whereIn('product_id', $productIds)
            ->pluck('course_id')
            ->all();

        $courses = Course::query()
            ->whereNotNull('latest_publication_id')
            ->whereNotNull('code')
            ->with('latestPublication:id,number')
            ->orderBy('title')
            ->get()
            ->map(fn (Course $c) => [
                'code' => $c->code,
                'title' => $c->title,
                'subject' => $c->subject,
                'grade_band' => $c->grade_band,
                'publication' => $c->latestPublication?->number,
                'entitled' => in_array($c->id, $entitledCourseIds, true),
            ])
            ->values();

        return response()->json(['courses' => $courses]);
    }

    /**
     * A published course's content (steps + blocks) for a client to render in its
     * own player — the native-render alternative to launching. Entitlement-checked.
     */
    public function content(Request $request, string $code): JsonResponse
    {
        $client = $request->attributes->get('client');

        $course = Course::where('code', $code)->whereNotNull('latest_publication_id')->first();
        abort_if($course === null, 404, 'No published course with that code.');
        abort_unless($this->entitled($client, $course), 403, 'Your organisation is not entitled to this course.');

        $snapshot = $course->latestPublication?->snapshot ?? [];
        $steps = [];
        $this->collectSteps($snapshot['tree'] ?? [], $steps);

        return response()->json([
            'course' => ['code' => $course->code, 'title' => $course->title, 'subject' => $course->subject],
            'steps' => $steps,
        ]);
    }

    /** Flatten content-bearing snapshot nodes (in tree order) into player steps. */
    private function collectSteps(array $nodes, array &$steps): void
    {
        foreach ($nodes as $n) {
            if (! empty($n['blocks'])) {
                $steps[] = ['id' => $n['id'] ?? null, 'title' => $n['title'] ?? '', 'blocks' => $n['blocks']];
            }
            if (! empty($n['children'])) {
                $this->collectSteps($n['children'], $steps);
            }
        }
    }

    private function entitled(Client $client, Course $course): bool
    {
        $productIds = ClientEntitlement::where('client_id', $client->id)
            ->where('status', 'active')
            ->pluck('product_id');

        return DB::table('product_courses')
            ->whereIn('product_id', $productIds)
            ->where('course_id', $course->id)
            ->exists();
    }
}
