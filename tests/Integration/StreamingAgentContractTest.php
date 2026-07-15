<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Integration;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Runtime\StreamingAgent;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
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
use NaokiTsuchiya\BEARAgUi\Input\Coerce;
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
        $second = self::requireIndex($events, 2, 'the third event');
        $third = self::requireIndex($events, 3, 'the fourth event');

        static::assertInstanceOf(TextMessageContent::class, $second);
        static::assertSame('hello ', $second->delta);
        static::assertInstanceOf(TextMessageContent::class, $third);
        static::assertSame('world', $third->delta);
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

        $second = self::requireIndex($events, 1, 'the second event');
        $third = self::requireIndex($events, 2, 'the third event');
        $fifth = self::requireIndex($events, 4, 'the fifth event');

        static::assertInstanceOf(ToolCallStart::class, $second);
        static::assertSame('call-1', $second->toolCallId);
        static::assertSame('search', $second->toolCallName);
        static::assertInstanceOf(ToolCallArgs::class, $third);
        static::assertSame('{"q":"hi"}', $third->delta);
        static::assertInstanceOf(ToolCallResult::class, $fifth);
        static::assertSame('call-1', $fifth->toolCallId);
        static::assertSame('hits', $fifth->content);
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
            static fn(AgUiEventInterface $e): bool => (
                $e instanceof ToolCallStart
                || $e instanceof ToolCallArgs
                || $e instanceof ToolCallEnd
                || $e instanceof ToolCallResult
            ),
        ));

        $te0 = self::requireIndex($toolEvents, 0, 'tool event 0');
        $te1 = self::requireIndex($toolEvents, 1, 'tool event 1');
        $te2 = self::requireIndex($toolEvents, 2, 'tool event 2');
        $te3 = self::requireIndex($toolEvents, 3, 'tool event 3');
        $te4 = self::requireIndex($toolEvents, 4, 'tool event 4');
        $te5 = self::requireIndex($toolEvents, 5, 'tool event 5');
        $te7 = self::requireIndex($toolEvents, 7, 'tool event 7');

        static::assertInstanceOf(ToolCallStart::class, $te0);
        static::assertSame('call-1', $te0->toolCallId);
        static::assertInstanceOf(ToolCallStart::class, $te1);
        static::assertSame('call-2', $te1->toolCallId);

        // First tool_result is FIFO call-1.
        static::assertInstanceOf(ToolCallArgs::class, $te2);
        static::assertSame('call-1', $te2->toolCallId);
        static::assertInstanceOf(ToolCallEnd::class, $te3);
        static::assertInstanceOf(ToolCallResult::class, $te4);
        static::assertSame('call-1', $te4->toolCallId);
        static::assertSame('A', $te4->content);

        static::assertInstanceOf(ToolCallArgs::class, $te5);
        static::assertSame('call-2', $te5->toolCallId);
        static::assertInstanceOf(ToolCallResult::class, $te7);
        static::assertSame('B', $te7->content);
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

        $decoded = Coerce::stringKeyedArray(json_decode(json_encode($finished, JSON_THROW_ON_ERROR), true));
        if ($decoded === null) {
            static::fail('expected the event to encode to a JSON object');
        }

        $outcome = self::requireArray($decoded, 'outcome', 'the event outcome');
        static::assertSame('interrupt', self::requireString($outcome, 'type', 'the outcome type'));

        $interrupts = self::requireArray($outcome, 'interrupts', 'the outcome interrupts');
        $interrupt = self::requireArray($interrupts, 0, 'the first interrupt');

        static::assertSame('tool_confirmation', self::requireString($interrupt, 'reason', 'the interrupt reason'));
        static::assertSame('call-1', self::requireString($interrupt, 'toolCallId', 'the interrupt toolCallId'));
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

    /**
     * Fetches a value at a key/index, failing loudly rather than returning a
     * possibly-undefined value. Shared by every guard clause above so the
     * class carries one branch per shape instead of one per call site.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $container
     * @param TKey                $key
     *
     * @return TValue
     */
    private static function requireIndex(array $container, int|string $key, string $label): mixed
    {
        if (!array_key_exists($key, $container)) {
            static::fail(sprintf('expected %s to be present', $label));
        }

        return $container[$key];
    }

    /**
     * Fetches an array field, failing loudly rather than returning a
     * possibly-undefined or possibly-non-array value.
     *
     * @param array<array-key, mixed> $container
     *
     * @return array<array-key, mixed>
     */
    private static function requireArray(array $container, int|string $key, string $label): array
    {
        if (!array_key_exists($key, $container) || !is_array($container[$key])) {
            static::fail(sprintf('expected %s to be an array', $label));
        }

        return $container[$key];
    }

    /**
     * Fetches a string field, failing loudly rather than returning a
     * possibly-undefined or possibly-non-string value.
     *
     * @param array<array-key, mixed> $container
     */
    private static function requireString(array $container, int|string $key, string $label): string
    {
        if (!array_key_exists($key, $container) || !is_string($container[$key])) {
            static::fail(sprintf('expected %s to be a string', $label));
        }

        return $container[$key];
    }
}
