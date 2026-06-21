<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

/**
 * Read-side of the tool-call registry — used by the adapter to enrich
 * AgentEvents with the real id / input / content captured from the wire.
 *
 * @api
 */
interface ToolCallView
{
    /**
     * Pop the next started tool call in FIFO order (the order TOOL_USE_START
     * arrived on the LLM stream). Returns null when no more starts are queued.
     */
    public function nextStarted(): StartedToolCall|null;

    /**
     * Look up the result of a previously dispatched tool call. Returns null
     * when no result was recorded (e.g. unregistered tool that the agent
     * short-circuited without calling the dispatcher).
     */
    public function resultFor(string $id): ToolCallOutcome|null;
}
