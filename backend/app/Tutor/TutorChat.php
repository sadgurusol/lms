<?php

namespace App\Tutor;

use App\Ai\AiReply;
use App\Ai\AnthropicClient;
use App\Models\TutorConversation;
use App\Models\TutorMessage;
use Illuminate\Support\Str;

/**
 * Orchestrates one tutor turn: ground on the (pinned) publication, replay the
 * trimmed history, ask the model, and persist both sides. See docs/12-ai-tutor.md.
 */
final class TutorChat
{
    /** How many prior turns to replay — a simple token guard for phase 1. */
    private const HISTORY_TURNS = 20;

    private const MAX_REPLY_TOKENS = 1024;

    public function __construct(
        private readonly AnthropicClient $ai,
        private readonly CourseContext $context,
        private readonly Retrieval $retrieval,
    ) {}

    public function reply(TutorConversation $conversation, string $userMessage, ?string $focusNodeId): TutorMessage
    {
        [$system, $history, $citations] = $this->prepare($conversation, $userMessage, $focusNodeId);

        $reply = $this->ai->chat($system, $history, self::MAX_REPLY_TOKENS);

        return $this->persistReply($conversation, $reply, $citations);
    }

    /**
     * As {@see reply()}, but streams the answer: $onText is called with each text
     * delta as it arrives. The persisted message is returned once complete.
     *
     * @param  callable(string): void  $onText
     */
    public function streamReply(
        TutorConversation $conversation,
        string $userMessage,
        ?string $focusNodeId,
        callable $onText,
    ): TutorMessage {
        [$system, $history, $citations] = $this->prepare($conversation, $userMessage, $focusNodeId);

        $reply = $this->ai->stream($system, $history, $onText, self::MAX_REPLY_TOKENS);

        return $this->persistReply($conversation, $reply, $citations);
    }

    /**
     * Ground the turn and record the learner's message. Shared by both paths.
     *
     * @return array{0: string, 1: list<array{role: string, content: string}>, 2: list<array{id: string, label: string}>}
     */
    private function prepare(TutorConversation $conversation, string $userMessage, ?string $focusNodeId): array
    {
        // Retrieve the sections most relevant to the question (empty when no
        // embeddings exist — the tutor still grounds on outline + focus).
        $retrieved = $this->retrieval->relevantNodes($conversation->publication, $userMessage);
        $grounding = $this->context->build($conversation->publication, $focusNodeId, $retrieved);

        // Persist the learner's turn first, so a failed model call still leaves a
        // faithful transcript and the message can be retried.
        $conversation->messages()->create([
            'role' => TutorMessage::ROLE_USER,
            'content' => $userMessage,
        ]);

        if ($conversation->title === null) {
            $conversation->update(['title' => Str::limit(trim($userMessage), 60)]);
        }

        return [
            $this->systemPrompt($conversation, $grounding['text']),
            $this->history($conversation),
            $grounding['citations'],
        ];
    }

    /**
     * @param  list<array{id: string, label: string}>  $citations
     */
    private function persistReply(TutorConversation $conversation, AiReply $reply, array $citations): TutorMessage
    {
        return $conversation->messages()->create([
            'role' => TutorMessage::ROLE_ASSISTANT,
            'content' => $reply->text,
            'citations' => $citations,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
        ]);
    }

    private function systemPrompt(TutorConversation $conversation, string $courseMaterial): string
    {
        $title = $conversation->course->title;

        return <<<PROMPT
        You are a patient, encouraging subject tutor for a learner studying "{$title}".

        Teach ONLY from the course material provided below. If the answer is not in
        the material, say so plainly and point the learner to the closest relevant
        section rather than inventing facts.

        Be Socratic: guide with questions, hints, and worked examples, and check the
        learner's understanding. Do NOT simply hand over answers to exercises or do a
        learner's graded work for them — help them get there themselves.

        You have no access to quiz or test questions or their answer keys, and you
        must never claim to. If asked for quiz answers, encourage the learner to
        reason it out and point them to the material.

        When you draw on a section, name it so the learner can find it. Keep replies
        concise and pitched at the learner's level.

        --- COURSE MATERIAL ---
        {$courseMaterial}
        PROMPT;
    }

    /**
     * The recent turns, oldest-first, in the shape the Messages API expects.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(TutorConversation $conversation): array
    {
        return $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_TURNS)
            ->get()
            ->reverse()
            ->map(fn (TutorMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }
}
