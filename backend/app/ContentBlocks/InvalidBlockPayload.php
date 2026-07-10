<?php

namespace App\ContentBlocks;

use RuntimeException;

final class InvalidBlockPayload extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $errors = [];

    /** @param array<string, mixed> $errors */
    public static function failedSchema(BlockType $type, array $errors): self
    {
        $summary = json_encode($errors, JSON_THROW_ON_ERROR);

        $e = new self("Payload for a [{$type->value}] block does not match its schema: {$summary}");
        $e->errors = $errors;

        return $e;
    }

    public static function mediaMissing(BlockType $type, string $mediaId): self
    {
        return new self("A [{$type->value}] block references media [{$mediaId}], which does not exist.");
    }

    public static function wrongMediaKind(BlockType $type, string $expected, string $actual): self
    {
        return new self("A [{$type->value}] block requires {$expected} media, but the referenced asset is {$actual}.");
    }

    public static function mediaIdMismatch(BlockType $type, string $column, string $payload): self
    {
        return new self(
            "A [{$type->value}] block has media_id [{$column}] on the column but [{$payload}] in the payload."
        );
    }
}
