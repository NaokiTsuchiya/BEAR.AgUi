<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Fake;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;
use RuntimeException;
use Throwable;

use function array_shift;
use function is_string;

/**
 * Scripted DispatcherInterface — queues scripted outcomes per tool name and
 * returns/throws them in the order they were queued. Used by both the C5 unit
 * tests of the recording decorator and the C7 contract tests that drive a
 * real StreamingAgent.
 */
final class FakeDispatcher implements DispatcherInterface
{
    /** @var array<string, list<array{kind:string, value:mixed}|Throwable>> */
    private array $scripts = [];

    /** @var list<ToolCall> */
    public array $calls = [];

    public function queueSuccess(string $toolName, mixed $content): void
    {
        $this->scripts[$toolName][] = ['kind' => 'success', 'value' => $content];
    }

    public function queueError(string $toolName, string $message): void
    {
        $this->scripts[$toolName][] = ['kind' => 'error', 'value' => $message];
    }

    public function queueThrow(string $toolName, Throwable $error): void
    {
        $this->scripts[$toolName][] = $error;
    }

    #[Override]
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        $this->calls[] = $toolCall;
        $queue = $this->scripts[$toolCall->name] ?? [];
        if ($queue === []) {
            throw new RuntimeException('No scripted result for tool ' . $toolCall->name);
        }

        $next = array_shift($queue);
        $this->scripts[$toolCall->name] = $queue;

        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next['kind'] === 'error') {
            return ToolResult::error($toolCall->id, is_string($next['value']) ? $next['value'] : 'error');
        }

        return ToolResult::success($toolCall->id, $next['value']);
    }
}
