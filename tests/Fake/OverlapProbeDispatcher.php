<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Fake;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;
use Swoole\Coroutine;

use function max;

/**
 * Coroutine-aware dispatcher probe: suspends briefly inside dispatch() so
 * concurrent dispatches can overlap, and records the maximum number of
 * in-flight calls. `maxActive > 1` proves real overlap without asserting
 * wall-clock timings (deterministic under the coroutine scheduler — every
 * fanned-out coroutine enters dispatch() before the first sleep wakes up).
 *
 * Requires a coroutine context (Coroutine::sleep).
 */
final class OverlapProbeDispatcher implements DispatcherInterface
{
    public int $active = 0;
    public int $maxActive = 0;

    /** @var list<string> Tool names in the order dispatch() was entered. */
    public array $dispatchedNames = [];

    public function __construct(
        private readonly float $sleepSeconds = 0.005,
    ) {}

    #[Override]
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        $this->active++;
        $this->maxActive = max($this->maxActive, $this->active);
        $this->dispatchedNames[] = $toolCall->name;

        Coroutine::sleep($this->sleepSeconds);

        $this->active--;

        return ToolResult::success($toolCall->id, 'result:' . $toolCall->name);
    }
}
