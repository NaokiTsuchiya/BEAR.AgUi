<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Tool call: end of the streamed call. Required before TOOL_CALL_RESULT
 * per AG-UI ordering (Start → [Args] → End → Result).
 *
 * @api
 */
final readonly class ToolCallEnd implements AgUiEventInterface
{
    public function __construct(
        public string $toolCallId,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TOOL_CALL_END';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'toolCallId' => $this->toolCallId];
    }
}
