<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Tool call: streamed arguments (JSON fragment) for the current tool call.
 *
 * @api
 */
final readonly class ToolCallArgs implements AgUiEventInterface
{
    public function __construct(
        public string $toolCallId,
        public string $delta,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TOOL_CALL_ARGS';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'toolCallId' => $this->toolCallId, 'delta' => $this->delta];
    }
}
