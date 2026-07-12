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
     * Pop the oldest started tool call recorded for `$toolName` (the order
     * TOOL_USE_START arrived on the LLM stream, tracked per name). Returns
     * null when no start is queued for that name.
     *
     * Keyed by name because the high-level AgentEvent timeline only carries
     * tool names; per-name pairing keeps start↔result correlation intact
     * when results are recorded concurrently and out of start order
     * (D9 revised by D29).
     */
    public function takeStarted(string $toolName): StartedToolCall|null;

    /**
     * Look up the result of a previously dispatched tool call. Returns null
     * when no result was recorded (e.g. unregistered tool that the agent
     * short-circuited without calling the dispatcher).
     */
    public function resultFor(string $id): ToolCallOutcome|null;
}
