<?php

namespace App\Services\Publishing;

use JsonSerializable;

final class Finding implements JsonSerializable
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    private function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $message,
        public readonly string $anchorType,
        public readonly ?string $anchorId,
    ) {}

    public static function error(string $code, string $message, string $anchorType = 'course', ?string $anchorId = null): self
    {
        return new self($code, self::ERROR, $message, $anchorType, $anchorId);
    }

    public static function warning(string $code, string $message, string $anchorType = 'course', ?string $anchorId = null): self
    {
        return new self($code, self::WARNING, $message, $anchorType, $anchorId);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'anchor' => ['type' => $this->anchorType, 'id' => $this->anchorId],
        ];
    }
}
