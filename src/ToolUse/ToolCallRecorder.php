<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;

/**
 * Write-side of the tool-call registry — used by the decorators that observe
 * ToolUse's low-level wire and dispatch.
 *
 * Kept separate from {@see ToolCallView} so the adapter only sees the read
 * surface (ISP). The same {@see ToolCallRegistry} implements both.
 *
 * @api
 */
interface ToolCallRecorder
{
    /** TOOL_USE_START arrived on the LLM stream: a new tool call is starting. */
    public function recordStart(string $id, string $name): void;

    /** TOOL_USE_DELTA arrived on the LLM stream: a JSON-fragment of input. */
    public function appendInput(string $id, string $delta): void;

    /** Dispatcher returned a result: capture id + input + content + isError. */
    public function recordResult(ToolCall $call, ToolResult $result): void;
}
