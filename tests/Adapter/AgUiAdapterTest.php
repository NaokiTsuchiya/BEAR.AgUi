<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;
use NaokiTsuchiya\BEARAgUi\Support\RecordingLogger;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Run-level behavior of the adapter pipeline: text framing, terminal
 * interrupt / error mapping. Tool-call correlation lives in
 * {@see AgUiAdapterToolCorrelationTest}.
 */
#[CoversClass(AgUiAdapter::class)]
final class AgUiAdapterTest extends TestCase
{
    public function testTextDeltaWrappedByStartAndEndAroundRun(): void
    {
        $registry = new ToolCallRegistry();
        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::textDelta('hi'),
                AgentEvent::textDelta(' there'),
                AgentEvent::completed('hi there'),
            ]),
            't',
            'r',
            $registry,
        ));

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

    public function testConfirmationRequiredTerminatesRunWithInterruptOutcome(): void
    {
        $registry = new ToolCallRegistry();
        $adapter = new AgUiAdapter(new NullLogger());

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::confirmationRequired('writer', 'call-9', ['path' => '/x'], 'About to write /x'),
                // The agent would normally yield further events if we sent true; the
                // adapter should stop consuming as soon as it emits the interrupt.
                AgentEvent::completed('never seen'),
            ]),
            't',
            'r',
            $registry,
        ));

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
        $adapter = new AgUiAdapter($logger);

        $events = $this->collect($adapter->run(
            $this->generator([
                AgentEvent::textDelta('partial'),
                AgentEvent::error('Max iterations reached'),
                AgentEvent::completed('never seen'),
            ]),
            't',
            'r',
            $registry,
        ));

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
        $adapter = new AgUiAdapter($logger);

        $throwing = (static function (): Generator {
            yield AgentEvent::textDelta('hi');
            throw new RuntimeException('boom');
        })();

        $events = $this->collect($adapter->run($throwing, 't', 'r', $registry));

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
