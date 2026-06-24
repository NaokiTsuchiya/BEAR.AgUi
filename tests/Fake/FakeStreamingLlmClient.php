<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Fake;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use Override;
use RuntimeException;

use function array_shift;

/**
 * Scripted StreamingLlmClient — each chatStream() call yields the next
 * scripted StreamEvent[] in queue order.
 *
 * StreamingAgent calls chatStream() once per iteration of its reason/act loop.
 * Tests use {@see self::queueScript()} to push one script per expected
 * iteration. A script terminates with MESSAGE_STOP carrying stopReason
 * "end_turn" (final answer) or "tool_use" (next iteration after dispatch).
 */
final class FakeStreamingLlmClient implements StreamingLlmClientInterface
{
    /** @var list<list<StreamEvent>> */
    private array $scripts = [];

    public int $callCount = 0;

    /** @param list<StreamEvent> $events */
    public function queueScript(array $events): void
    {
        $this->scripts[] = $events;
    }

    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    #[Override]
    public function chatStream(string $system, array $messages, array $tools): Generator
    {
        if ($this->scripts === []) {
            throw new RuntimeException('FakeStreamingLlmClient has no more scripted runs.');
        }

        $script = array_shift($this->scripts);
        $this->callCount++;

        foreach ($script as $event) {
            yield $event;
        }
    }
}
