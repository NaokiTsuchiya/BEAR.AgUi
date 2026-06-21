<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use JsonSerializable;
use Override;

/**
 * AG-UI Interrupt entry: an item inside RunFinished.outcome.interrupts[].
 *
 * `id` and `reason` are required; everything else is optional and is omitted
 * from the serialized JSON when unset.
 *
 * @api
 */
final readonly class Interrupt implements JsonSerializable
{
    /** @param array<string, mixed>|null $responseSchema */
    public function __construct(
        public string $id,
        public string $reason,
        public ?string $message = null,
        public ?string $toolCallId = null,
        public ?array $responseSchema = null,
        public ?string $expiresAt = null,
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = ['id' => $this->id, 'reason' => $this->reason];
        if ($this->message !== null) {
            $data['message'] = $this->message;
        }

        if ($this->toolCallId !== null) {
            $data['toolCallId'] = $this->toolCallId;
        }

        if ($this->responseSchema !== null) {
            $data['responseSchema'] = $this->responseSchema;
        }

        if ($this->expiresAt !== null) {
            $data['expiresAt'] = $this->expiresAt;
        }

        if ($this->metadata !== null) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }
}
