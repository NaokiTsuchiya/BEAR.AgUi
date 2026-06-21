<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\Adapter;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\Interrupt;
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
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

#[CoversClass(AgUiAdapter::class)]
final class AgUiAdapterTest extends TestCase
{
    public function testTextDeltaWrappedByStartAndEndAroundRun(): void
    {
        $registry = new ToolCallRegistry();
        $adapter = new AgUiAdapter('t', 'r', $registry);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::textDelta('hi'),
            AgentEvent::textDelta(' there'),
            AgentEvent::completed('hi there'),
        ])));

        self::assertInstanceOf(RunStarted::class, $events[0]);
        self::assertInstanceOf(TextMessageStart::class, $events[1]);
        self::assertInstanceOf(TextMessageContent::class, $events[2]);
        self::assertInstanceOf(TextMessageContent::class, $events[3]);
        self::assertInstanceOf(TextMessageEnd::class, $events[4]);
        self::assertInstanceOf(RunFinished::class, $events[5]);
        self::assertCount(6, $events);

        $messageId = $events[1]->messageId;
        self::assertSame($messageId, $events[2]->messageId);
        self::assertSame($messageId, $events[3]->messageId);
        self::assertSame($messageId, $events[4]->messageId);
        self::assertSame('hi', $events[2]->delta);
        self::assertSame(' there', $events[3]->delta);
    }

    public function testSingleToolCallEmitsStartArgsEndResultWithRealRegistryData(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'search');
        $registry->appendInput('call-1', '{"q":"hi"}');
        $registry->recordResult(
            new ToolCall('call-1', 'search', ['q' => 'hi']),
            ToolResult::success('call-1', 'hits'),
        );

        $adapter = new AgUiAdapter('t', 'r', $registry);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::toolStart('search'),
            AgentEvent::toolResult('search'),
            AgentEvent::completed(''),
        ])));

        self::assertInstanceOf(RunStarted::class, $events[0]);
        self::assertInstanceOf(ToolCallStart::class, $events[1]);
        self::assertInstanceOf(ToolCallArgs::class, $events[2]);
        self::assertInstanceOf(ToolCallEnd::class, $events[3]);
        self::assertInstanceOf(ToolCallResult::class, $events[4]);
        self::assertInstanceOf(RunFinished::class, $events[5]);

        self::assertSame('call-1', $events[1]->toolCallId);
        self::assertSame('search', $events[1]->toolCallName);
        self::assertSame('call-1', $events[2]->toolCallId);
        self::assertSame('{"q":"hi"}', $events[2]->delta);
        self::assertSame('call-1', $events[3]->toolCallId);
        self::assertSame('call-1', $events[4]->toolCallId);
        self::assertSame('hits', $events[4]->content);
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

        $adapter = new AgUiAdapter('t', 'r', $registry);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::toolStart('a'),
            AgentEvent::toolStart('b'),
            AgentEvent::toolResult('a'),
            AgentEvent::toolResult('b'),
            AgentEvent::completed(''),
        ])));

        // Lifecycle: RunStarted, 2× ToolCallStart, 2× (Args, End, Result), RunFinished
        self::assertInstanceOf(RunStarted::class, $events[0]);
        self::assertInstanceOf(ToolCallStart::class, $events[1]);
        self::assertInstanceOf(ToolCallStart::class, $events[2]);
        self::assertSame('call-1', $events[1]->toolCallId);
        self::assertSame('call-2', $events[2]->toolCallId);

        // First tool_result resolves call-1 (FIFO).
        self::assertInstanceOf(ToolCallArgs::class, $events[3]);
        self::assertSame('call-1', $events[3]->toolCallId);
        self::assertInstanceOf(ToolCallEnd::class, $events[4]);
        self::assertSame('call-1', $events[4]->toolCallId);
        self::assertInstanceOf(ToolCallResult::class, $events[5]);
        self::assertSame('call-1', $events[5]->toolCallId);
        self::assertSame('A', $events[5]->content);

        // Second tool_result resolves call-2.
        self::assertInstanceOf(ToolCallArgs::class, $events[6]);
        self::assertSame('call-2', $events[6]->toolCallId);
        self::assertInstanceOf(ToolCallEnd::class, $events[7]);
        self::assertInstanceOf(ToolCallResult::class, $events[8]);
        self::assertSame('B', $events[8]->content);
        self::assertSame('call-2', $events[8]->toolCallId);

        self::assertInstanceOf(RunFinished::class, $events[9]);
    }

    public function testToolResultWithoutRegistryFallsBackToEmptyContent(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'unregistered');
        // Intentionally no recordResult — simulates unregistered tool branch.

        $adapter = new AgUiAdapter('t', 'r', $registry);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::toolStart('unregistered'),
            AgentEvent::toolResult('unregistered'),
            AgentEvent::completed(''),
        ])));

        self::assertInstanceOf(ToolCallEnd::class, $events[2]);
        self::assertSame('call-1', $events[2]->toolCallId);
        self::assertInstanceOf(ToolCallResult::class, $events[3]);
        self::assertSame('call-1', $events[3]->toolCallId);
        self::assertSame('', $events[3]->content);
        // No ToolCallArgs is emitted when input is empty.
        self::assertInstanceOf(RunFinished::class, $events[4]);
    }

    public function testToolStartClosesOpenTextMessage(): void
    {
        $registry = new ToolCallRegistry();
        $registry->recordStart('call-1', 'search');
        $registry->recordResult(new ToolCall('call-1', 'search', []), ToolResult::success('call-1', 'ok'));

        $adapter = new AgUiAdapter('t', 'r', $registry);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::textDelta('thinking…'),
            AgentEvent::toolStart('search'),
            AgentEvent::toolResult('search'),
            AgentEvent::completed(''),
        ])));

        self::assertInstanceOf(TextMessageStart::class, $events[1]);
        self::assertInstanceOf(TextMessageContent::class, $events[2]);
        self::assertInstanceOf(TextMessageEnd::class, $events[3]);
        self::assertInstanceOf(ToolCallStart::class, $events[4]);
    }

    public function testConfirmationRequiredTerminatesRunWithInterruptOutcome(): void
    {
        $registry = new ToolCallRegistry();
        $adapter = new AgUiAdapter('t', 'r', $registry);

        $events = $this->collect($adapter->run($this->generator([
            AgentEvent::confirmationRequired('writer', 'call-9', ['path' => '/x'], 'About to write /x'),
            // The agent would normally yield further events if we sent true; the
            // adapter should stop consuming as soon as it emits the interrupt.
            AgentEvent::completed('never seen'),
        ])));

        self::assertCount(2, $events);
        self::assertInstanceOf(RunStarted::class, $events[0]);
        self::assertInstanceOf(RunFinished::class, $events[1]);

        $decoded = json_decode(json_encode($events[1], JSON_THROW_ON_ERROR), true);
        self::assertSame('interrupt', $decoded['outcome']['type']);
        self::assertCount(1, $decoded['outcome']['interrupts']);
        self::assertSame('tool_confirmation', $decoded['outcome']['interrupts'][0]['reason']);
        self::assertSame('About to write /x', $decoded['outcome']['interrupts'][0]['message']);
        self::assertSame('call-9', $decoded['outcome']['interrupts'][0]['toolCallId']);
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

        self::assertInstanceOf(RunStarted::class, $events[0]);
        self::assertInstanceOf(TextMessageStart::class, $events[1]);
        self::assertInstanceOf(TextMessageContent::class, $events[2]);
        self::assertInstanceOf(TextMessageEnd::class, $events[3]);
        self::assertInstanceOf(RunError::class, $events[4]);
        self::assertCount(5, $events);

        self::assertSame('Internal agent error.', $events[4]->message);
        self::assertSame('AGENT_ERROR', $events[4]->code);
        self::assertNotEmpty($logger->entries);
        self::assertStringContainsString('Max iterations reached', (string) $logger->entries[0]['context']['message']);
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

        self::assertInstanceOf(RunStarted::class, $events[0]);
        self::assertInstanceOf(TextMessageStart::class, $events[1]);
        self::assertInstanceOf(TextMessageContent::class, $events[2]);
        self::assertInstanceOf(TextMessageEnd::class, $events[3]);
        self::assertInstanceOf(RunError::class, $events[4]);
        self::assertSame('Internal agent error.', $events[4]->message);
        self::assertSame('AGENT_ERROR', $events[4]->code);

        self::assertCount(1, $logger->entries);
        self::assertInstanceOf(RuntimeException::class, $logger->entries[0]['context']['exception']);
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

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level:string, message:string, context:array<string,mixed>}> */
    public array $entries = [];

    #[Override]
    public function emergency(string|Stringable $message, array $context = []): void { $this->log(LogLevel::EMERGENCY, $message, $context); }
    #[Override]
    public function alert(string|Stringable $message, array $context = []): void { $this->log(LogLevel::ALERT, $message, $context); }
    #[Override]
    public function critical(string|Stringable $message, array $context = []): void { $this->log(LogLevel::CRITICAL, $message, $context); }
    #[Override]
    public function error(string|Stringable $message, array $context = []): void { $this->log(LogLevel::ERROR, $message, $context); }
    #[Override]
    public function warning(string|Stringable $message, array $context = []): void { $this->log(LogLevel::WARNING, $message, $context); }
    #[Override]
    public function notice(string|Stringable $message, array $context = []): void { $this->log(LogLevel::NOTICE, $message, $context); }
    #[Override]
    public function info(string|Stringable $message, array $context = []): void { $this->log(LogLevel::INFO, $message, $context); }
    #[Override]
    public function debug(string|Stringable $message, array $context = []): void { $this->log(LogLevel::DEBUG, $message, $context); }

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->entries[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
