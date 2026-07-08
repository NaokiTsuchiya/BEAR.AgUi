<?php

declare(strict_types=1);

namespace Example\Server;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use Example\Server\Tool\GetTimeTool;
use Override;

/**
 * Demo dispatcher (D21): routes tool calls by name. `get_time` executes
 * {@see GetTimeTool}; anything else is an error result fed back to the
 * model. `ask_confirmation` is intentionally absent — its confirm flag
 * interrupts the run before dispatch.
 */
final readonly class DemoDispatcher implements DispatcherInterface
{
    public function __construct(private GetTimeTool $getTime = new GetTimeTool()) {}

    #[Override]
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        if ($toolCall->name === GetTimeTool::NAME) {
            return ToolResult::success($toolCall->id, ($this->getTime)($toolCall->input));
        }

        return ToolResult::error($toolCall->id, "Unknown tool: {$toolCall->name}");
    }
}
