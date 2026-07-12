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
    ) {}
}
