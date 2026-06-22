<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Tool call: result of the tool invocation. `messageId` is required per
 * AG-UI spec.
 *
 * @api
 */
final readonly class ToolCallResult implements AgUiEventInterface
{
    public function __construct(
        public string $messageId,
        public string $toolCallId,
        public string $content,
        public string $role,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'TOOL_CALL_RESULT';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'messageId' => $this->messageId,
            'toolCallId' => $this->toolCallId,
            'content' => $this->content,
            'role' => $this->role,
        ];
    }
}
