<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Text message: start of an assistant message block.
 *
 * @api
 */
final readonly class TextMessageStart implements AgUiEventInterface
{
    public function __construct(
        public string $messageId,
        public string $role = 'assistant',
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TEXT_MESSAGE_START';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'messageId' => $this->messageId, 'role' => $this->role];
    }
}
