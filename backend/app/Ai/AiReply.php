<?php

namespace App\Ai;

/**
 * A model's reply plus its token usage, for persistence and cost accounting.
 */
final readonly class AiReply
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        // Why the model stopped: 'end_turn', 'max_tokens', etc. Null when unknown
        // (e.g. streamed replies). A 'max_tokens' stop means the reply was cut off.
        public ?string $stopReason = null,
    ) {}
}
