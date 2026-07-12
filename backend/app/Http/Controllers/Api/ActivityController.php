<?php

namespace App\Http\Controllers\Api;

use App\Activity\Verb;
use App\Exceptions\InvalidActivityEvent;
use App\Http\Controllers\Controller;
use App\Services\Activity\RecordActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    public function __construct(private readonly RecordActivity $recorder) {}

    /**
     * Batch ingest from the client's offline outbox.
     *
     * Partial success is the normal case. The response reports each event so the
     * client can drain its outbox precisely and retry only what failed.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.id' => ['required', 'uuid'],
            'events.*.verb' => ['required', Rule::in(Verb::names())],
            'events.*.course_id' => ['required', 'uuid'],
            'events.*.node_id' => ['sometimes', 'nullable', 'uuid'],
            'events.*.assessment_id' => ['sometimes', 'nullable', 'uuid'],
            'events.*.attempt_id' => ['sometimes', 'nullable', 'uuid'],
            'events.*.occurred_at' => ['sometimes', 'date'],
            'events.*.payload' => ['sometimes', 'array'],
            'events.*.device' => ['sometimes', 'array'],

            // Deliberately absent: client_id. It is stamped from the session.
        ]);

        $results = [];

        foreach ($data['events'] as $index => $event) {
            try {
                $accepted = $this->recorder->handle($request->user(), $event);

                $results[] = [
                    'index' => $index,
                    'id' => $event['id'],
                    'status' => $accepted ? 'accepted' : 'duplicate',
                ];
            } catch (InvalidActivityEvent $e) {
                // Client-fixable. A QueryException is ours and fails loudly.
                $results[] = [
                    'index' => $index,
                    'id' => $event['id'],
                    'status' => 'rejected',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results], 202);
    }
}
