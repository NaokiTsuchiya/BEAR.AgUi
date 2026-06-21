<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;
use Throwable;

/**
 * Decorates {@see DispatcherInterface} so each successful dispatch deposits
 * the id / input / content / isError of the call into the {@see ToolCallRecorder}.
 *
 * The adapter later reads this through {@see ToolCallView::resultFor()} to
 * render TOOL_CALL_ARGS / TOOL_CALL_END / TOOL_CALL_RESULT with real data
 * (instead of the tool name, which is all AgentEvent::TOOL_RESULT carries).
 *
 * This whole decorator is a removable shim — see decisions.md D10. When
 * ToolUse enriches AgentEvent itself, delete this class and its registry.
 *
 * @api
 */
final readonly class RecordingDispatcher implements DispatcherInterface
{
    public function __construct(
        private DispatcherInterface $inner,
        private ToolCallRecorder $recorder,
    ) {
    }

    /**
     * @throws Throwable propagated from the wrapped dispatcher; the calling
     *                   StreamingAgent translates it into a tool-result error.
     */
    #[Override]
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        $result = $this->inner->dispatch($toolCall);
        $this->recorder->recordResult($toolCall, $result);

        return $result;
    }
}
