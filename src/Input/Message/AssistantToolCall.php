<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

/**
 * One entry of {@see AssistantMessage::$toolCalls} — the OpenAI-shaped
 * `{ id, type:"function", function: { name, arguments } }` record AG-UI
 * uses to represent prior tool calls in conversation history.
 *
 * Per spec the wire `arguments` is a JSON-encoded object string; the
 * parser decodes and validates it up-front so consumers see the already-
 * typed `array<string, mixed>` ready to feed into a ToolUse `tool_use`
 * content block — no lazy re-parsing, no silent `[]` fallback.
 *
 * @api
 */
final readonly class AssistantToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
