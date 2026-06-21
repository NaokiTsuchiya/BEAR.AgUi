<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Text message: end of the current message block.
 *
 * @api
 */
final readonly class TextMessageEnd implements AgUiEventInterface
{
    public function __construct(
        public string $messageId,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TEXT_MESSAGE_END';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'messageId' => $this->messageId];
    }
}
