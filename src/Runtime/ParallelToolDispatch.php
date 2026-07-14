<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\AgentEvent;
use BEAR\ToolUse\Runtime\PendingToolCall;
use BEAR\ToolUse\Runtime\ToolList;
use Generator;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Throwable;

use function array_values;
use function json_decode;
use function ksort;

/**
 * The dispatch fan-out of {@see ParallelStreamingAgent} — the one piece of
 * logic the sequential StreamingAgent does not have:
 *
 *  - pass 1 (serial, pending order): unknown tools error out; confirmable
 *    tools yield CONFIRMATION_REQUIRED and, when approved, execute serially
 *    before the parallel wave; denials record a cancelled result.
 *  - pass 2 (parallel): remaining plain calls fan out on a {@see WaitGroup}.
 *  - pass 3: one `tool_result` AgentEvent per pending call, in pending order.
 *
 * @internal
 */
final readonly class ParallelToolDispatch
{
    public function __construct(
        private DispatcherInterface $dispatcher,
    ) {}

    /**
     * @param list<PendingToolCall> $pendingToolCalls
     * @param string                $currentText      LLM text for the confirmation prompt
     *
     * @return Generator<int, AgentEvent, bool, list<ToolResult>>
     */
    public function run(array $pendingToolCalls, string $currentText, ToolList $toolList): Generator
    {
        /** @var array<int, ToolResult> $results */
        $results = [];
        /** @var array<int, ToolCall> $plainCalls */
        $plainCalls = [];

        foreach ($pendingToolCalls as $index => $pending) {
            $toolCall = $this->toToolCall($pending);
            if (!$toolList->has($toolCall->name)) {
                $results[$index] = ToolResult::error($toolCall->id, 'Tool is not enabled: ' . $toolCall->name);
                continue;
            }

            if ($toolList->isConfirmable($toolCall->name)) {
                $approved = yield AgentEvent::confirmationRequired(
                    $toolCall->name,
                    $toolCall->id,
                    $toolCall->input,
                    $currentText,
                );
                $results[$index] = $approved ? $this->dispatch($toolCall) : ToolResult::cancelled($toolCall->id);
                continue;
            }

            $plainCalls[$index] = $toolCall;
        }

        $this->dispatchConcurrently($plainCalls, $results);

        ksort($results);
        foreach ($pendingToolCalls as $pending) {
            yield AgentEvent::toolResult($pending->name);
        }

        return array_values($results);
    }

    /**
     * Fan the plain calls out on coroutines and join. Each coroutine writes
     * to a distinct index, so the shared array needs no synchronization
     * beyond the WaitGroup join (PHP array writes are uninterruptible).
     *
     * @param array<int, ToolCall>   $plainCalls
     * @param array<int, ToolResult> $results
     */
    private function dispatchConcurrently(array $plainCalls, array &$results): void
    {
        if ($plainCalls === []) {
            return;
        }

        $waitGroup = new WaitGroup();
        foreach ($plainCalls as $index => $toolCall) {
            $waitGroup->add();
            Coroutine::create(function () use ($waitGroup, $index, $toolCall, &$results): void {
                try {
                    $results[$index] = $this->dispatch($toolCall);
                } finally {
                    $waitGroup->done();
                }
            });
        }

        $waitGroup->wait();
    }

    private function toToolCall(PendingToolCall $pending): ToolCall
    {
        $decoded = json_decode($pending->inputJson, true);

        /** @var array<string, mixed> $input */
        $input = is_array($decoded) ? $decoded : [];

        return new ToolCall(id: $pending->id, name: $pending->name, input: $input);
    }

    /** Mirror the sequential agent: a throwing dispatcher becomes an error result. */
    private function dispatch(ToolCall $toolCall): ToolResult
    {
        try {
            return $this->dispatcher->dispatch($toolCall);
        } catch (Throwable $e) {
            return ToolResult::error($toolCall->id, $e::class . ': ' . $e->getMessage());
        }
    }
}
