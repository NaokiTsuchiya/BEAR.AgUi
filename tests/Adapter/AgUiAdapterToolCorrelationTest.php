<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallArgs;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallEnd;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallResult;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallStart;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tool-call start↔result correlation through the adapter: registry data
 * enrichment, per-name id pairing (D9 revised by D29), and fallbacks.
 */
#[CoversClass(AgUiAdapter::class)]
final class AgUiAdapterToolCorrelationTest extends TestCase
{
    /** @throws RuntimeException */
    public function testSingleToolCallEmitsStartArgsEndResultWithRealRegistryData(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'search');
        $registry->appendInput('call-1', '{"q":"hi"}');
        $registry->recordResult(new ToolCall('call-1', 'search', ['q' => 'hi']), ToolResult::success('call-1', 'hits'));

        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::toolStart('search'),
                AgentEvent::toolResult('search'),
                AgentEvent::completed(''),
            ]),
            't',
            'r',
            $registry,
        ));

        if (!array_key_exists(4, $events)) {
            throw new RuntimeException('Expected at least 5 events.');
        }

        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(ToolCallStart::class, $events[1]);
        static::assertInstanceOf(ToolCallArgs::class, $events[2]);
        static::assertInstanceOf(ToolCallEnd::class, $events[3]);
        static::assertInstanceOf(ToolCallResult::class, $events[4]);

        static::assertSame('call-1', $events[1]->toolCallId);
        static::assertSame('search', $events[1]->toolCallName);
        static::assertSame('call-1', $events[2]->toolCallId);
        static::assertSame('{"q":"hi"}', $events[2]->delta);
        static::assertSame('call-1', $events[3]->toolCallId);
        static::assertSame('call-1', $events[4]->toolCallId);
        static::assertSame('hits', $events[4]->content);
    }

    /** @throws RuntimeException */
    public function testParallelToolsAreCorrelatedFromRegistryInStartOrder(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'a');
        $registry->recordStart('call-2', 'b');
        $registry->appendInput('call-1', '{"x":1}');
        $registry->appendInput('call-2', '{"y":2}');
        $registry->recordResult(new ToolCall('call-1', 'a', ['x' => 1]), ToolResult::success('call-1', 'A'));
        $registry->recordResult(new ToolCall('call-2', 'b', ['y' => 2]), ToolResult::success('call-2', 'B'));

        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::toolStart('a'),
                AgentEvent::toolStart('b'),
                AgentEvent::toolResult('a'),
                AgentEvent::toolResult('b'),
                AgentEvent::completed(''),
            ]),
            't',
            'r',
            $registry,
        ));

        if (!array_key_exists(8, $events)) {
            throw new RuntimeException('Expected at least 9 events.');
        }

        // Lifecycle: RunStarted, 2× ToolCallStart, 2× (Args, End, Result), RunFinished
        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(ToolCallStart::class, $events[1]);
        static::assertInstanceOf(ToolCallStart::class, $events[2]);
        static::assertSame('call-1', $events[1]->toolCallId);
        static::assertSame('call-2', $events[2]->toolCallId);

        static::assertInstanceOf(ToolCallArgs::class, $events[3]);
        static::assertSame('call-1', $events[3]->toolCallId);
        static::assertInstanceOf(ToolCallEnd::class, $events[4]);
        static::assertSame('call-1', $events[4]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $events[5]);
        static::assertSame('call-1', $events[5]->toolCallId);
        static::assertSame('A', $events[5]->content);

        static::assertInstanceOf(ToolCallArgs::class, $events[6]);
        static::assertSame('call-2', $events[6]->toolCallId);
        static::assertInstanceOf(ToolCallEnd::class, $events[7]);
        static::assertInstanceOf(ToolCallResult::class, $events[8]);
        static::assertSame('B', $events[8]->content);
        static::assertSame('call-2', $events[8]->toolCallId);
    }

    /** @throws RuntimeException */
    public function testParallelToolResultsArrivingOutOfStartOrderPairById(): void
    {
        // D29: with concurrent dispatch, results for different tools may be
        // yielded out of start order. Pairing is per tool name → id, so the
        // b-result must bind to call-2 even though call-1 started first.
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'a');
        $registry->recordStart('call-2', 'b');
        $registry->appendInput('call-1', '{"x":1}');
        $registry->appendInput('call-2', '{"y":2}');
        $registry->recordResult(new ToolCall('call-1', 'a', ['x' => 1]), ToolResult::success('call-1', 'A'));
        $registry->recordResult(new ToolCall('call-2', 'b', ['y' => 2]), ToolResult::success('call-2', 'B'));

        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::toolStart('a'),
                AgentEvent::toolStart('b'),
                AgentEvent::toolResult('b'),
                AgentEvent::toolResult('a'),
                AgentEvent::completed(''),
            ]),
            't',
            'r',
            $registry,
        ));

        if (!array_key_exists(8, $events)) {
            throw new RuntimeException('Expected at least 9 events.');
        }

        static::assertInstanceOf(ToolCallStart::class, $events[1]);
        static::assertInstanceOf(ToolCallStart::class, $events[2]);
        static::assertSame('call-1', $events[1]->toolCallId);
        static::assertSame('call-2', $events[2]->toolCallId);

        // First tool_result is b's — it must resolve call-2, not call-1.
        static::assertInstanceOf(ToolCallArgs::class, $events[3]);
        static::assertSame('call-2', $events[3]->toolCallId);
        static::assertSame('{"y":2}', $events[3]->delta);
        static::assertInstanceOf(ToolCallEnd::class, $events[4]);
        static::assertSame('call-2', $events[4]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $events[5]);
        static::assertSame('call-2', $events[5]->toolCallId);
        static::assertSame('B', $events[5]->content);

        static::assertInstanceOf(ToolCallArgs::class, $events[6]);
        static::assertSame('call-1', $events[6]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $events[8]);
        static::assertSame('call-1', $events[8]->toolCallId);
        static::assertSame('A', $events[8]->content);
    }

    /** @throws RuntimeException */
    public function testSameNameToolCallsPairInStartOrder(): void
    {
        // Two calls to the same tool in one turn: within a name the agent
        // yields results in start order, so the per-name FIFO pairs them.
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'x');
        $registry->recordStart('call-2', 'x');
        $registry->recordResult(new ToolCall('call-1', 'x', []), ToolResult::success('call-1', 'first'));
        $registry->recordResult(new ToolCall('call-2', 'x', []), ToolResult::success('call-2', 'second'));

        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::toolStart('x'),
                AgentEvent::toolStart('x'),
                AgentEvent::toolResult('x'),
                AgentEvent::toolResult('x'),
                AgentEvent::completed(''),
            ]),
            't',
            'r',
            $registry,
        ));

        if (!array_key_exists(6, $events)) {
            throw new RuntimeException('Expected at least 7 events.');
        }

        static::assertInstanceOf(ToolCallStart::class, $events[1]);
        static::assertSame('call-1', $events[1]->toolCallId);
        static::assertInstanceOf(ToolCallStart::class, $events[2]);
        static::assertSame('call-2', $events[2]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $events[4]);
        static::assertSame('call-1', $events[4]->toolCallId);
        static::assertSame('first', $events[4]->content);
        static::assertInstanceOf(ToolCallResult::class, $events[6]);
        static::assertSame('call-2', $events[6]->toolCallId);
        static::assertSame('second', $events[6]->content);
    }

    /** @throws RuntimeException */
    public function testToolResultWithoutRegistryFallsBackToEmptyContent(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'unregistered');
        // Intentionally no recordResult — simulates unregistered tool branch.

        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::toolStart('unregistered'),
                AgentEvent::toolResult('unregistered'),
                AgentEvent::completed(''),
            ]),
            't',
            'r',
            $registry,
        ));

        if (!array_key_exists(4, $events)) {
            throw new RuntimeException('Expected at least 5 events.');
        }

        static::assertInstanceOf(ToolCallEnd::class, $events[2]);
        static::assertSame('call-1', $events[2]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $events[3]);
        static::assertSame('call-1', $events[3]->toolCallId);
        static::assertSame('', $events[3]->content);
        // No ToolCallArgs is emitted when input is empty.
        static::assertInstanceOf(\NaokiTsuchiya\BEARAgUi\Event\RunFinished::class, $events[4]);
    }

    /** @throws RuntimeException */
    public function testToolStartClosesOpenTextMessage(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'search');
        $registry->recordResult(new ToolCall('call-1', 'search', []), ToolResult::success('call-1', 'ok'));

        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::textDelta('thinking…'),
                AgentEvent::toolStart('search'),
                AgentEvent::toolResult('search'),
                AgentEvent::completed(''),
            ]),
            't',
            'r',
            $registry,
        ));

        if (!array_key_exists(4, $events)) {
            throw new RuntimeException('Expected at least 5 events.');
        }

        static::assertInstanceOf(TextMessageStart::class, $events[1]);
        static::assertInstanceOf(TextMessageContent::class, $events[2]);
        static::assertInstanceOf(TextMessageEnd::class, $events[3]);
        static::assertInstanceOf(ToolCallStart::class, $events[4]);
    }

    /**
     * @param iterable<AgUiEventInterface> $stream
     *
     * @return list<AgUiEventInterface>
     */
    private function collect(iterable $stream): array
    {
        $out = [];
        foreach ($stream as $event) {
            $out[] = $event;
        }

        return $out;
    }

    /**
     * @param list<AgentEvent> $events
     *
     * @return Generator<int, AgentEvent, mixed, void>
     */
    private function generator(array $events): Generator
    {
        foreach ($events as $event) {
            yield $event;
        }
    }
}
