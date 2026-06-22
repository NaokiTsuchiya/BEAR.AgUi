<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Tool call: agent is invoking a tool. Emitted early (Tier 2) when the tool id
 * arrives on the wire, before arguments are streamed.
 *
 * @api
 */
final readonly class ToolCallStart implements AgUiEventInterface
{
    public function __construct(
        public string $toolCallId,
        public string $toolCallName,
        public string|null $parentMessageId,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'TOOL_CALL_START';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->type(),
            'toolCallId' => $this->toolCallId,
            'toolCallName' => $this->toolCallName,
        ];
        if ($this->parentMessageId !== null) {
            $data['parentMessageId'] = $this->parentMessageId;
        }

        return $data;
    }
}
