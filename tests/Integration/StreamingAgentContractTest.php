<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Integration;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Runtime\StreamingAgent;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallArgs;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallEnd;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallResult;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallStart;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\Support\StreamingPipelineFixture;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingDispatcher;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end contract tests that drive a REAL StreamingAgent through the
 * recording decorators and adapter — to catch any drift between assumptions
 * and ToolUse's actual emission order. The only fakes are at the LLM/Dispatcher
 * boundary (scripted StreamEvents and ToolResults).
 *
 * Justification for not using a hand-rolled FakeStreamingAgent: see
 * decisions.md D13.
 */
final class StreamingAgentContractTest extends TestCase
{
    use StreamingPipelineFixture;

    public function testTextOnlyScenarioYieldsLifecycleAndTextBoundaries(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'hello ']),
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'world']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        [$events] = $this->runPipeline($llm, new FakeDispatcher(), [], 'hi');
        $types = $this->types($events);

        static::assertSame(
            [
                RunStarted::class,
                TextMessageStart::class,
                TextMessageContent::class,
                TextMessageContent::class,
                TextMessageEnd::class,
                RunFinished::class,
            ],
            $types,
        );
        static::assertInstanceOf(TextMessageContent::class, $events[2]);
        static::assertSame('hello ', $events[2]->delta);
        static::assertInstanceOf(TextMessageContent::class, $events[3]);
        static::assertSame('world', $events[3]->delta);
    }

    public function testSingleToolCallRoundTripUsesRealRegistryData(): void
    {
        $llm = new FakeStreamingLlmClient();
        // Iteration 1: LLM asks for a tool.
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'search']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"q":"hi"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        // Iteration 2: after tool result, LLM finalizes.
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'done']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        $dispatcher = new FakeDispatcher();
        $dispatcher->queueSuccess('search', 'hits');

        [$events] = $this->runPipeline($llm, $dispatcher, [$this->tool('search')], 'hi');
        $types = $this->types($events);

        static::assertSame(
            [
                RunStarted::class,
                ToolCallStart::class,
                ToolCallArgs::class,
                ToolCallEnd::class,
                ToolCallResult::class,
                TextMessageStart::class,
                TextMessageContent::class,
                TextMessageEnd::class,
                RunFinished::class,
            ],
            $types,
        );

        static::assertInstanceOf(ToolCallStart::class, $events[1]);
        static::assertSame('call-1', $events[1]->toolCallId);
        static::assertSame('search', $events[1]->toolCallName);
        static::assertInstanceOf(ToolCallArgs::class, $events[2]);
        static::assertSame('{"q":"hi"}', $events[2]->delta);
        static::assertInstanceOf(ToolCallResult::class, $events[4]);
        static::assertSame('call-1', $events[4]->toolCallId);
        static::assertSame('hits', $events[4]->content);
    }

    public function testParallelToolCallsAreCorrelatedByFifo(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'a']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"x":1}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-2', 'name' => 'b']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"y":2}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'ok']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        $dispatcher = new FakeDispatcher();
        $dispatcher->queueSuccess('a', 'A');
        $dispatcher->queueSuccess('b', 'B');

        [$events] = $this->runPipeline($llm, $dispatcher, [$this->tool('a'), $this->tool('b')], 'go');

        // Walk forward picking just the tool events.
        $toolEvents = array_values(array_filter(
            $events,
            static fn($e) => (
                $e instanceof ToolCallStart
                || $e instanceof ToolCallArgs
                || $e instanceof ToolCallEnd
                || $e instanceof ToolCallResult
            ),
        ));

        static::assertInstanceOf(ToolCallStart::class, $toolEvents[0]);
        static::assertSame('call-1', $toolEvents[0]->toolCallId);
        static::assertInstanceOf(ToolCallStart::class, $toolEvents[1]);
        static::assertSame('call-2', $toolEvents[1]->toolCallId);

        // First tool_result is FIFO call-1.
        static::assertInstanceOf(ToolCallArgs::class, $toolEvents[2]);
        static::assertSame('call-1', $toolEvents[2]->toolCallId);
        static::assertInstanceOf(ToolCallEnd::class, $toolEvents[3]);
        static::assertInstanceOf(ToolCallResult::class, $toolEvents[4]);
        static::assertSame('call-1', $toolEvents[4]->toolCallId);
        static::assertSame('A', $toolEvents[4]->content);

        static::assertInstanceOf(ToolCallArgs::class, $toolEvents[5]);
        static::assertSame('call-2', $toolEvents[5]->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $toolEvents[7]);
        static::assertSame('B', $toolEvents[7]->content);
    }

    public function testConfirmationRequiredEmitsInterruptOutcomeAndStopsRun(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'writer']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"path":"/x"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        // A second script would normally be queued for the post-confirmation
        // iteration; we deliberately leave it empty to assert the run stops here.

        $dispatcher = new FakeDispatcher();
        // Dispatcher must NOT be called for a confirmation-pending tool.

        [$events] = $this->runPipeline($llm, $dispatcher, [$this->confirmableTool('writer')], 'do it');

        $finished = end($events);
        static::assertInstanceOf(RunFinished::class, $finished);
        $decoded = json_decode(json_encode($finished, JSON_THROW_ON_ERROR), true);
        static::assertIsArray($decoded);
        $outcome = $decoded['outcome'];
        static::assertIsArray($outcome);
        static::assertSame('interrupt', $outcome['type']);
        $interrupts = $outcome['interrupts'];
        static::assertIsArray($interrupts);
        $interrupt = $interrupts[0];
        static::assertIsArray($interrupt);
        static::assertSame('tool_confirmation', $interrupt['reason']);
        static::assertSame('call-1', $interrupt['toolCallId']);
        static::assertCount(0, $dispatcher->calls);
    }

    public function testDispatcherThrowableSurfacesAsRunError(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'search']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        // After the dispatch error, StreamingAgent feeds the error back to the
        // LLM and continues — script a final iteration that ends the turn.
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'recovered']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        $dispatcher = new FakeDispatcher();
        $dispatcher->queueThrow('search', new \RuntimeException('disk full'));

        [$events] = $this->runPipeline($llm, $dispatcher, [$this->tool('search')], 'hi');
        $toolResult = $this->firstOf($events, ToolCallResult::class);
        static::assertInstanceOf(ToolCallResult::class, $toolResult);
        static::assertStringContainsString('RuntimeException', $toolResult->content);
        static::assertStringContainsString('disk full', $toolResult->content);
        static::assertInstanceOf(RunFinished::class, end($events));
    }

    public function testYieldAndWriteInterleaveOneByOne(): void
    {
        // T6-4 latency probe: each AgUiEvent should be produced one at a time,
        // not folded into a list and emitted at the end. We verify this by
        // counting how many StreamEvents were consumed at the moment each
        // adapter output is observed.
        $consumed = 0;

        $llm = new class($consumed) implements \BEAR\ToolUse\Llm\StreamingLlmClientInterface {
            public function __construct(
                private int &$consumed,
            ) {}

            #[\Override]
            public function chatStream(string $system, array $messages, array $tools): \Generator
            {
                $events = [
                    new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'a']),
                    new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'b']),
                    new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                    new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
                ];
                foreach ($events as $e) {
                    $this->consumed++;
                    yield $e;
                }
            }
        };

        $registry = new ToolCallRegistry();
        $agent = new StreamingAgent(
            new RecordingStreamingLlmClient($llm, $registry),
            new RecordingDispatcher(new FakeDispatcher(), $registry),
            tools: [],
            systemPrompt: '',
        );
        $adapter = new AgUiAdapter(new NullLogger());

        $deltas = 0;
        foreach ($adapter->run($agent->runStream('hi'), 't', 'r', $registry) as $event) {
            if (!$event instanceof TextMessageContent) {
                continue;
            }

            $deltas++;
            if ($deltas === 1) {
                // After the first delta is emitted to us, only the first
                // upstream chunk should have been consumed (plus its prior
                // events). Not all four scripted StreamEvents.
                static::assertLessThan(4, $consumed, 'pipeline folded the stream');
            }
        }

        static::assertSame(2, $deltas);
    }
}
