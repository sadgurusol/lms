<?php

namespace App\Http\Controllers\Api;

use App\Entitlements\EntitlementResolver;
use App\Exceptions\NotEntitled;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Course;
use App\Models\TutorConversation;
use App\Models\TutorMessage;
use App\Tutor\TutorBudget;
use App\Tutor\TutorChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The learner's AI tutor. Every route is entitlement-gated through
 * EntitlementResolver — the same boundary as course content — and a conversation
 * is private to the learner who owns it. See docs/12-ai-tutor.md.
 */
class TutorController extends Controller
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly TutorChat $chat,
        private readonly TutorBudget $budget,
    ) {}

    /** The learner's tutor status: whether it's on for them, and their usage. */
    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'enabled' => $this->tutorEnabled($request),
            'used' => $this->budget->usedThisMonth($user),
            'budget' => $this->budget->budget(),
            'remaining' => $this->budget->remaining($user),
        ]]);
    }

    /** The learner's conversations on a course. */
    public function index(Request $request, Course $course): JsonResponse
    {
        $this->assertEntitled($request, $course);

        $conversations = TutorConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->latest('updated_at')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (TutorConversation $c) => $this->conversation($c))->all(),
        ]);
    }

    /** Begin a conversation, pinned to the course's current publication. */
    public function start(Request $request, Course $course): JsonResponse
    {
        $this->assertEntitled($request, $course);
        $this->assertTutorEnabled($request);
        abort_if($course->latest_publication_id === null, 404, 'This course has no published content yet.');

        $conversation = TutorConversation::create([
            'user_id' => $request->user()->id,
            'course_id' => $course->id,
            'publication_id' => $course->latest_publication_id,
            'client_id' => $request->user()->currentClientId(),
        ]);

        return response()->json(['data' => $this->conversation($conversation)], 201);
    }

    /** A conversation with its full transcript. */
    public function show(Request $request, TutorConversation $conversation): JsonResponse
    {
        $this->assertOwner($request, $conversation);

        $conversation->load(['messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id')]);

        return response()->json([
            'data' => [
                ...$this->conversation($conversation),
                'messages' => $conversation->messages->map(fn (TutorMessage $m) => $this->messageData($m))->all(),
            ],
        ]);
    }

    /** Ask a question; returns the tutor's reply in one shot. */
    public function message(Request $request, TutorConversation $conversation): JsonResponse
    {
        [$conversation, $data] = $this->prepareTurn($request, $conversation);

        $reply = $this->chat->reply($conversation, $data['content'], $data['focus_node_id'] ?? null);

        return response()->json(['data' => $this->messageData($reply)]);
    }

    /**
     * Ask a question and stream the reply token-by-token as Server-Sent Events:
     * `data:` frames carry `{delta}` chunks, a final `event: done` frame carries
     * the persisted message id and its citations, and `event: error` reports a
     * failure mid-stream.
     */
    public function stream(Request $request, TutorConversation $conversation): StreamedResponse
    {
        [$conversation, $data] = $this->prepareTurn($request, $conversation);

        return response()->stream(function () use ($conversation, $data) {
            try {
                $message = $this->chat->streamReply(
                    $conversation,
                    $data['content'],
                    $data['focus_node_id'] ?? null,
                    fn (string $token) => $this->sse('delta', ['delta' => $token]),
                );

                $this->sse('done', ['id' => $message->id, 'citations' => $message->citations]);
            } catch (\Throwable $e) {
                report($e);
                $this->sse('error', ['message' => 'The tutor is unavailable right now.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',   // don't let nginx buffer the stream
        ]);
    }

    /**
     * Shared front matter for both reply paths: ownership, a re-checked
     * entitlement (it can be revoked mid-conversation), and validation.
     *
     * @return array{0: TutorConversation, 1: array<string, mixed>}
     */
    private function prepareTurn(Request $request, TutorConversation $conversation): array
    {
        $this->assertOwner($request, $conversation);
        $this->assertEntitled($request, $conversation->course);
        $this->assertTutorEnabled($request);

        abort_if(
            $this->budget->exceeded($request->user()),
            429,
            "You've reached this month's tutor usage limit. It resets at the start of next month.",
        );

        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'focus_node_id' => ['nullable', 'uuid'],
        ]);

        return [$conversation, $data];
    }

    /** A B2B client can turn the tutor off for its learners; B2C is always on. */
    private function assertTutorEnabled(Request $request): void
    {
        abort_unless(
            $this->tutorEnabled($request),
            403,
            'Your institution has turned off the AI tutor.',
        );
    }

    private function tutorEnabled(Request $request): bool
    {
        $clientId = $request->user()->currentClientId();

        if ($clientId === null) {
            return true;
        }

        $client = Client::find($clientId);

        return $client === null || $client->aiTutorEnabled();
    }

    /** Emit one SSE frame and push it to the client immediately. */
    private function sse(string $event, mixed $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function assertEntitled(Request $request, Course $course): void
    {
        $clientId = $request->user()->currentClientId();

        if (! $this->resolver->entitles($request->user(), $course, $clientId)) {
            throw NotEntitled::forCourse($course, $clientId);
        }
    }

    /** A conversation is private; a stranger gets 404, never a hint it exists. */
    private function assertOwner(Request $request, TutorConversation $conversation): void
    {
        abort_if($conversation->user_id !== $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function conversation(TutorConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'course_id' => $conversation->course_id,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function messageData(TutorMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'citations' => $message->citations,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
