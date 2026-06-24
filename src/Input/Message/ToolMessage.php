<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use Override;

/**
 * AG-UI ToolMessage — the result of a prior assistant `tool_use` call.
 *
 * `toolCallId` correlates with the originating
 * {@see AssistantToolCall::$id}; success vs failure lives on the wrapped
 * {@see ToolOutcome} so consumers query the outcome directly
 * (`$msg->outcome->isError()`) rather than reaching for a nullable
 * discriminator on the message itself.
 *
 * @api
 */
final readonly class ToolMessage implements Message
{
    public function __construct(
        public string $id,
        public string $toolCallId,
        public ToolOutcome $outcome,
    ) {}

    #[Override]
    public function role(): string
    {
        return 'tool';
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}
