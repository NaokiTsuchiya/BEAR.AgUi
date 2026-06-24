<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Text message: a token/delta of the current message. `delta` must be non-empty.
 *
 * @api
 */
final readonly class TextMessageContent implements AgUiEventInterface
{
    public function __construct(
        public string $messageId,
        public string $delta,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'TEXT_MESSAGE_CONTENT';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'messageId' => $this->messageId, 'delta' => $this->delta];
    }
}
