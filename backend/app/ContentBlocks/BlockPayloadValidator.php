<?php

namespace App\ContentBlocks;

use App\Models\Media;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a block's `payload` against the JSON Schema for its type, and its
 * `media_id` against the media kind the type demands.
 *
 * Postgres enforces *which types* a level permits (trigger). It cannot read a
 * JSON Schema, so payload shape is enforced here — but from the model's saving
 * hook, not a FormRequest, so a seeder or a queue job cannot slip past it.
 */
final class BlockPayloadValidator
{
    private Validator $validator;

    /** @var array<string, object> */
    private array $schemas = [];

    public function __construct()
    {
        $this->validator = new Validator;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidBlockPayload
     */
    public function validate(BlockType $type, array $payload, ?string $mediaId = null): void
    {
        $this->validateShape($type, $payload);
        $this->validateMedia($type, $payload, $mediaId);
    }

    /** @param array<string, mixed> $payload */
    private function validateShape(BlockType $type, array $payload): void
    {
        // json_decode(json_encode(...)) is not a no-op: opis needs stdClass for
        // objects, and an empty PHP array is otherwise indistinguishable from [].
        $data = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = $this->validator->validate($data, $this->schemaFor($type));

        if ($result->hasError()) {
            $errors = (new ErrorFormatter)->format($result->error());

            throw InvalidBlockPayload::failedSchema($type, $errors);
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateMedia(BlockType $type, array $payload, ?string $mediaId): void
    {
        $requiredKind = $type->requiredMediaKind();

        if ($requiredKind === null) {
            return;
        }

        // The column and the payload must agree; the column is what the FK and
        // the publish-time readiness check look at.
        $payloadMediaId = $payload['media_id'] ?? null;

        if ($mediaId !== null && $payloadMediaId !== null && $mediaId !== $payloadMediaId) {
            throw InvalidBlockPayload::mediaIdMismatch($type, $mediaId, (string) $payloadMediaId);
        }

        $id = $mediaId ?? $payloadMediaId;
        $media = Media::find($id);

        if ($media === null) {
            throw InvalidBlockPayload::mediaMissing($type, (string) $id);
        }

        if ($media->kind !== $requiredKind) {
            throw InvalidBlockPayload::wrongMediaKind($type, $requiredKind, $media->kind);
        }
    }

    private function schemaFor(BlockType $type): object
    {
        return $this->schemas[$type->value] ??= json_decode(
            file_get_contents($type->schemaPath()) ?: '{}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
