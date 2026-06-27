<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use Override;

/**
 * AG-UI AssistantMessage — the model's prior turns.
 *
 * `content` is the response text (may be `null` if the turn was purely tool
 * calls); `toolCalls` is the OpenAI-shape function calls the model produced
 * on that turn. The history mapper turns this into a ToolUse
 * `Message::assistant([text + tool_use blocks])`.
 *
 * @api
 */
final readonly class AssistantMessage implements Message
{
    /** @param list<AssistantToolCall> $toolCalls */
    public function __construct(
        public string $id,
        public string|null $content,
        public array $toolCalls,
    ) {}

    #[Override]
    public function role(): string
    {
        return 'assistant';
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}
