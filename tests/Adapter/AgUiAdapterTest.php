<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallArgs;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallEnd;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallResult;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallStart;
use NaokiTsuchiya\BEARAgUi\Support\RecordingLogger;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgUiAdapter::class)]
final class AgUiAdapterTest extends TestCase
{
    public function testTextDeltaWrappedByStartAndEndAroundRun(): void
    {
        $registry = new ToolCallRegistry();
        $adapter = new AgUiAdapter('t', 'r', $registry, null);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::textDelta('hi'),
            AgentEvent::textDelta(' there'),
            AgentEvent::completed('hi there'),
        ])));

        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(TextMessageStart::class, $events[1]);
        static::assertInstanceOf(TextMessageContent::class, $events[2]);
        static::assertInstanceOf(TextMessageContent::class, $events[3]);
        static::assertInstanceOf(TextMessageEnd::class, $events[4]);
        static::assertInstanceOf(RunFinished::class, $events[5]);
        static::assertCount(6, $events);

        $messageId = $events[1]->messageId;
        static::assertSame($messageId, $events[2]->messageId);
        static::assertSame($messageId, $events[3]->messageId);
        static::assertSame($messageId, $events[4]->messageId);
        static::assertSame('hi', $events[2]->delta);
        static::assertSame(' there', $events[3]->delta);
    }

    public function testSingleToolCallEmitsStartArgsEndResultWithRealRegistryData(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'search');
        $registry->appendInput('call-1', '{"q":"hi"}');
        $registry->recordResult(new ToolCall('call-1', 'search', ['q' => 'hi']), ToolResult::success('call-1', 'hits'));

        $adapter = new AgUiAdapter('t', 'r', $registry, null);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::toolStart('search'),
            AgentEvent::toolResult('search'),
            AgentEvent::completed(''),
        ])));

        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(ToolCallStart::class, $events[1]);
        static::assertInstanceOf(ToolCallArgs::class, $events[2]);
        static::assertInstanceOf(ToolCallEnd::class, $events[3]);
        static::assertInstanceOf(ToolCallResult::class, $events[4]);
        static::assertInstanceOf(RunFinished::class, $events[5]);

        static::assertSame('call-1', $events[1]->toolCallId);
        static::assertSame('search', $events[1]->toolCallName);
        static::assertSame('call-1', $events[2]->toolCallId);
        static::assertSame('{"q":"hi"}', $events[2]->delta);
        static::assertSame('call-1', $events[3]->toolCallId);
        static::assertSame('call-1', $events[4]->toolCallId);
        static::assertSame('hits', $events[4]->content);
    }

    public function testParallelToolsAreCorrelatedInFifoOrderFromRegistry(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'a');
        $registry->recordStart('call-2', 'b');
        $registry->appendInput('call-1', '{"x":1}');
        $registry->appendInput('call-2', '{"y":2}');
        $registry->recordResult(new ToolCall('call-1', 'a', ['x' => 1]), ToolResult::success('call-1', 'A'));
        $registry->recordResult(new ToolCall('call-2', 'b', ['y' => 2]), ToolResult::success('call-2', 'B'));

        $adapter = new AgUiAdapter('t', 'r', $registry, null);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::toolStart('a'),
            AgentEvent::toolStart('b'),
            AgentEvent::toolResult('a'),
            AgentEvent::toolResult('b'),
            AgentEvent::completed(''),
        ])));

        // Lifecycle: RunStarted, 2× ToolCallStart, 2× (Args, End, Result), RunFinished
        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(ToolCallStart::class, $events[1]);
        static::assertInstanceOf(ToolCallStart::class, $events[2]);
        static::assertSame('call-1', $events[1]->toolCallId);
        static::assertSame('call-2', $events[2]->toolCallId);

        // First tool_result resolves call-1 (FIFO).
        static::assertInstanceOf(ToolCallArgs::class, $events[3]);
        static::assertSame('call-1', $events[3]->toolCallId);
        static::assertInstanceOf(ToolCallEnd::class, $events[4]);
        static::assertSame('call-1', $events[4]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $events[5]);
        static::assertSame('call-1', $events[5]->toolCallId);
        static::assertSame('A', $events[5]->content);

        // Second tool_result resolves call-2.
        static::assertInstanceOf(ToolCallArgs::class, $events[6]);
        static::assertSame('call-2', $events[6]->toolCallId);
        static::assertInstanceOf(ToolCallEnd::class, $events[7]);
        static::assertInstanceOf(ToolCallResult::class, $events[8]);
        static::assertSame('B', $events[8]->content);
        static::assertSame('call-2', $events[8]->toolCallId);

        static::assertInstanceOf(RunFinished::class, $events[9]);
    }

    public function testToolResultWithoutRegistryFallsBackToEmptyContent(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'unregistered');
        // Intentionally no recordResult — simulates unregistered tool branch.

        $adapter = new AgUiAdapter('t', 'r', $registry, null);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::toolStart('unregistered'),
            AgentEvent::toolResult('unregistered'),
            AgentEvent::completed(''),
        ])));

        static::assertInstanceOf(ToolCallEnd::class, $events[2]);
        static::assertSame('call-1', $events[2]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $events[3]);
        static::assertSame('call-1', $events[3]->toolCallId);
        static::assertSame('', $events[3]->content);
        // No ToolCallArgs is emitted when input is empty.
        static::assertInstanceOf(RunFinished::class, $events[4]);
    }

    public function testToolStartClosesOpenTextMessage(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'search');
        $registry->recordResult(new ToolCall('call-1', 'search', []), ToolResult::success('call-1', 'ok'));

        $adapter = new AgUiAdapter('t', 'r', $registry, null);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::textDelta('thinking…'),
            AgentEvent::toolStart('search'),
            AgentEvent::toolResult('search'),
            AgentEvent::completed(''),
        ])));

        static::assertInstanceOf(TextMessageStart::class, $events[1]);
        static::assertInstanceOf(TextMessageContent::class, $events[2]);
        static::assertInstanceOf(TextMessageEnd::class, $events[3]);
        static::assertInstanceOf(ToolCallStart::class, $events[4]);
    }

    public function testConfirmationRequiredTerminatesRunWithInterruptOutcome(): void
    {
        $registry = new ToolCallRegistry();
        $adapter = new AgUiAdapter('t', 'r', $registry, null);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::confirmationRequired('writer', 'call-9', ['path' => '/x'], 'About to write /x'),
            // The agent would normally yield further events if we sent true; the
            // adapter should stop consuming as soon as it emits the interrupt.
            AgentEvent::completed('never seen'),
        ])));

        static::assertCount(2, $events);
        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(RunFinished::class, $events[1]);

        $decoded = json_decode(json_encode($events[1], JSON_THROW_ON_ERROR), true);
        static::assertSame('interrupt', $decoded['outcome']['type']);
        static::assertCount(1, $decoded['outcome']['interrupts']);
        static::assertSame('tool_confirmation', $decoded['outcome']['interrupts'][0]['reason']);
        static::assertSame('About to write /x', $decoded['outcome']['interrupts'][0]['message']);
        static::assertSame('call-9', $decoded['outcome']['interrupts'][0]['toolCallId']);
    }

    public function testAgentEventErrorBecomesRunErrorAndTerminates(): void
    {
        $registry = new ToolCallRegistry();
        $logger = new RecordingLogger();
        $adapter = new AgUiAdapter('t', 'r', $registry, $logger);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::textDelta('partial'),
            AgentEvent::error('Max iterations reached'),
            AgentEvent::completed('never seen'),
        ])));

        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(TextMessageStart::class, $events[1]);
        static::assertInstanceOf(TextMessageContent::class, $events[2]);
        static::assertInstanceOf(TextMessageEnd::class, $events[3]);
        static::assertInstanceOf(RunError::class, $events[4]);
        static::assertCount(5, $events);

        static::assertSame('Internal agent error.', $events[4]->message);
        static::assertSame('AGENT_ERROR', $events[4]->code);
        static::assertNotEmpty($logger->entries);
        static::assertStringContainsString(
            'Max iterations reached',
            (string) $logger->entries[0]['context']['message'],
        );
    }

    public function testThrownDuringStreamLogsExceptionAndEmitsGenericRunError(): void
    {
        $registry = new ToolCallRegistry();
        $logger = new RecordingLogger();
        $adapter = new AgUiAdapter('t', 'r', $registry, $logger);

        $throwing = (static function (): Generator {
            yield AgentEvent::textDelta('hi');
            throw new RuntimeException('boom');
        })();

        $events = $this->collect($adapter->run($throwing));

        static::assertInstanceOf(RunStarted::class, $events[0]);
        static::assertInstanceOf(TextMessageStart::class, $events[1]);
        static::assertInstanceOf(TextMessageContent::class, $events[2]);
        static::assertInstanceOf(TextMessageEnd::class, $events[3]);
        static::assertInstanceOf(RunError::class, $events[4]);
        static::assertSame('Internal agent error.', $events[4]->message);
        static::assertSame('AGENT_ERROR', $events[4]->code);

        static::assertCount(1, $logger->entries);
        static::assertInstanceOf(RuntimeException::class, $logger->entries[0]['context']['exception']);
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
