<?php

namespace App\Ai;

use App\Tutor\TutorChat;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A thin wrapper over the Anthropic Messages API.
 *
 * Deliberately minimal and side-effect free so it fakes cleanly in tests
 * (`Http::fake`). Higher-level policy — grounding, history, persistence — lives
 * in {@see TutorChat}, not here.
 */
class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function chat(string $system, array $messages, int $maxTokens = 1024): AiReply
    {
        $key = (string) config('services.anthropic.key');

        if ($key === '') {
            throw new RuntimeException('The AI tutor is not configured (missing ANTHROPIC_API_KEY).');
        }

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout(60)
            ->post(self::ENDPOINT, [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("The tutor is unavailable right now (status {$response->status()}).");
        }

        // Anthropic returns content as a list of blocks; concatenate the text.
        /** @var list<array{type?: string, text?: string}> $blocks */
        $blocks = $response->json('content', []);
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        if ($text === '') {
            throw new RuntimeException('The tutor returned an empty response.');
        }

        return new AiReply(
            text: $text,
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
        );
    }

    /**
     * A single-shot completion whose user message may carry rich content blocks
     * (e.g. a PDF document alongside instructions), for course generation. Longer
     * timeout and a bigger token ceiling than a chat turn.
     *
     * @param  list<array<string, mixed>>  $content  Anthropic content blocks.
     */
    public function complete(string $system, array $content, int $maxTokens = 16000): AiReply
    {
        $key = (string) config('services.anthropic.key');

        if ($key === '') {
            throw new RuntimeException('AI is not configured (missing ANTHROPIC_API_KEY).');
        }

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout(300)
            ->post(self::ENDPOINT, [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("The AI service failed (status {$response->status()}).");
        }

        /** @var list<array{type?: string, text?: string}> $blocks */
        $blocks = $response->json('content', []);
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        if ($text === '') {
            throw new RuntimeException('The AI returned an empty response.');
        }

        return new AiReply(
            text: $text,
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            stopReason: $response->json('stop_reason'),
        );
    }

    /**
     * Stream a reply, invoking $onText with each text delta as it arrives.
     * Returns the assembled reply once the stream ends.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onText
     */
    public function stream(string $system, array $messages, callable $onText, int $maxTokens = 1024): AiReply
    {
        $key = (string) config('services.anthropic.key');

        if ($key === '') {
            throw new RuntimeException('The AI tutor is not configured (missing ANTHROPIC_API_KEY).');
        }

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => self::API_VERSION,
        ])
            ->withOptions(['stream' => true])
            ->timeout(120)
            ->post(self::ENDPOINT, [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $messages,
                'stream' => true,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("The tutor is unavailable right now (status {$response->status()}).");
        }

        return $this->consume($response->toPsrResponse()->getBody(), $onText);
    }

    /**
     * Read Anthropic's SSE stream frame by frame, forwarding text deltas and
     * tallying usage.
     *
     * @param  callable(string): void  $onText
     */
    private function consume(StreamInterface $body, callable $onText): AiReply
    {
        $buffer = '';
        $text = '';
        $inputTokens = 0;
        $outputTokens = 0;

        while (! $body->eof()) {
            $buffer .= $body->read(2048);

            // SSE frames are separated by a blank line.
            while (($break = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $break);
                $buffer = substr($buffer, $break + 2);

                foreach (explode("\n", $frame) as $line) {
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $event = json_decode(trim(substr($line, 5)), true);
                    if (! is_array($event)) {
                        continue;
                    }

                    match ($event['type'] ?? '') {
                        'message_start' => $inputTokens = (int) ($event['message']['usage']['input_tokens'] ?? 0),
                        'content_block_delta' => $this->onDelta($event, $text, $onText),
                        'message_delta' => $outputTokens = (int) ($event['usage']['output_tokens'] ?? $outputTokens),
                        default => null,
                    };
                }
            }
        }

        return new AiReply($text, $inputTokens, $outputTokens);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  callable(string): void  $onText
     */
    private function onDelta(array $event, string &$text, callable $onText): void
    {
        if (($event['delta']['type'] ?? '') !== 'text_delta') {
            return;
        }

        $token = (string) ($event['delta']['text'] ?? '');
        if ($token === '') {
            return;
        }

        $text .= $token;
        $onText($token);
    }
}
