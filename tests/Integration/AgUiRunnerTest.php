<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Integration;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\Message as ToolUseMessage;
use BEAR\ToolUse\Schema\Tool as SchemaTool;
use Generator;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInput;
use NaokiTsuchiya\BEARAgUi\Sse\SseEncoder;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use NaokiTsuchiya\BEARAgUi\Support\RecordingSink;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use NaokiTsuchiya\BEARAgUi\ToolUse\StreamingAgentFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function array_column;
use function array_key_exists;
use function array_map;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * End-to-end tests of the facade: a real StreamingAgent (built by the
 * bundled factory) driven through the recording decorators and adapter,
 * framed by the real SseEncoder into a RecordingSink. The only fakes are
 * at the LLM / dispatcher boundary (scripted StreamEvents / ToolResults).
 *
 * @mago-expect lint:too-many-methods
 *
 * One method per AG-UI scenario keeps each contract independently
 * diagnosable.
 */
#[CoversClass(AgUiRunner::class)]
#[CoversClass(StreamingAgentFactory::class)]
final class AgUiRunnerTest extends TestCase
{
    public function testSingleTextTurnStreamsLifecycleAndText(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'hello']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);
        $sink = new RecordingSink();

        self::render(self::runner($llm, new FakeDispatcher(), []), self::input('hi'), $sink);

        static::assertSame(
            ['RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END', 'RUN_FINISHED'],
            self::types($sink),
        );
    }

    public function testHistoryIsSeededBeforeTheNewUserTurn(): void
    {
        $captured = [];
        $llm = new class($captured) implements StreamingLlmClientInterface {
            /** @param list<list<ToolUseMessage>> $captured */
            public function __construct(
                private array &$captured,
            ) {}

            /**
             * @param list<ToolUseMessage> $messages
             * @param list<SchemaTool>     $tools
             *
             * @return Generator<int, StreamEvent, mixed, void>
             */
            #[Override]
            public function chatStream(string $system, array $messages, array $tools): Generator
            {
                $this->captured[] = $messages;

                yield new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'ok']);
                yield new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP);
                yield new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']);
            }
        };

        $input = self::input('follow up', [
            new UserMessage('m1', 'first question'),
            new AssistantMessage('m2', 'first answer', []),
        ]);
        self::render(self::runner($llm, new FakeDispatcher(), []), $input, new RecordingSink());

        // The agent saw the seeded history followed by the new user turn.
        $messages = self::requireIndex($captured, 0, 'the LLM client to capture the seeded history');
        static::assertCount(3, $messages);

        $first = self::requireIndex($messages, 0, 'the first captured message');
        $second = self::requireIndex($messages, 1, 'the second captured message');
        $third = self::requireIndex($messages, 2, 'the third captured message');

        static::assertSame('user', $first->role);
        static::assertSame([['type' => 'text', 'text' => 'first question']], $first->content);
        static::assertSame('assistant', $second->role);
        static::assertSame([['type' => 'text', 'text' => 'first answer']], $second->content);
        static::assertSame('user', $third->role);
        static::assertSame([['type' => 'text', 'text' => 'follow up']], $third->content);
    }

    public function testDeclaredToolsAreIntersectedWithKnownToolsLeniently(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'search']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"q":"hi"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'done']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);
        $dispatcher = new FakeDispatcher();
        $dispatcher->queueSuccess('search', 'hits');
        $sink = new RecordingSink();

        // Agent knows 'search'; client declares 'search' (known) + 'browser'
        // (client-side, unknown). If the unknown name leaked into
        // enabledTools, AgentOptions::filterTools() would throw and the run
        // would surface RUN_ERROR — its absence proves the intersection.
        $input = self::input('hi', [], ['search', 'browser']);
        self::render(self::runner($llm, $dispatcher, [self::tool('search')]), $input, $sink);

        $types = self::types($sink);
        static::assertContains('TOOL_CALL_RESULT', $types);
        static::assertNotContains('RUN_ERROR', $types);
        $lastType = end($types);
        static::assertNotFalse($lastType, 'expected at least one event');

        static::assertSame('RUN_FINISHED', $lastType);
    }

    public function testConfirmationRequiredFinishesWithInterruptOutcome(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'writer']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"path":"/x"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        $dispatcher = new FakeDispatcher();
        $sink = new RecordingSink();

        $input = self::input('do it', [], ['writer']);
        self::render(self::runner($llm, $dispatcher, [self::confirmableTool('writer')]), $input, $sink);

        $events = self::decode($sink);
        $finished = end($events);
        static::assertNotFalse($finished, 'expected a decoded event');

        static::assertSame('RUN_FINISHED', self::requireString($finished, 'type', 'the event type'));

        $outcome = self::requireArray($finished, 'outcome', 'the event outcome');
        static::assertSame('interrupt', self::requireString($outcome, 'type', 'the outcome type'));

        $interrupts = self::requireArray($outcome, 'interrupts', 'the outcome interrupts');
        $interrupt = self::requireArray($interrupts, 0, 'the first interrupt');

        static::assertSame('tool_confirmation', self::requireString($interrupt, 'reason', 'the interrupt reason'));
        static::assertSame('call-1', self::requireString($interrupt, 'toolCallId', 'the interrupt toolCallId'));
        static::assertCount(0, $dispatcher->calls);
    }

    public function testRuntimeFailureMidStreamSurfacesAsRunErrorOver200(): void
    {
        // No scripted runs queued: the LLM throws when the agent pulls its
        // first stream — a mid-stream failure, distinct from a pre-flight
        // 400. The stream is already open at 200 by then.
        $sink = new RecordingSink();

        self::render(self::runner(new FakeStreamingLlmClient(), new FakeDispatcher(), []), self::input('hi'), $sink);

        $types = self::types($sink);
        $lastType = end($types);
        static::assertNotFalse($lastType, 'expected at least one event');

        static::assertSame('RUN_ERROR', $lastType);
    }

    /** @param list<SchemaTool> $tools */
    private static function runner(
        StreamingLlmClientInterface $llm,
        FakeDispatcher $dispatcher,
        array $tools,
    ): AgUiRunner {
        return new AgUiRunner(
            new StreamingAgentFactory($llm, $dispatcher, $tools, 'system'),
            new MessageHistoryMapper(),
            new AgUiAdapter(new NullLogger()),
            [],
        );
    }

    /** Play the host: frame the runner's event stream to SSE and capture it. */
    private static function render(AgUiRunner $runner, RunAgentInput $input, RecordingSink $sink): void
    {
        (new SseResponder(new SseEncoder()))->respond($runner->stream($input), $sink);
    }

    /**
     * @param list<Message> $history
     * @param list<string>  $declaredToolNames
     */
    private static function input(
        string $userMessage,
        array $history = [],
        array $declaredToolNames = [],
    ): RunAgentInput {
        if ($userMessage === '') {
            self::fail('userMessage must not be empty');
        }

        return new RunAgentInput('t', 'r', $userMessage, $history, $declaredToolNames, [], null, [], []);
    }

    private static function tool(string $name): SchemaTool
    {
        return new SchemaTool($name, '', ['type' => 'object', 'properties' => [], 'required' => []], false);
    }

    private static function confirmableTool(string $name): SchemaTool
    {
        return new SchemaTool($name, '', ['type' => 'object', 'properties' => [], 'required' => []], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decode(RecordingSink $sink): array
    {
        return array_map(self::decodeFrame(...), $sink->frames);
    }

    /** @return array<string, mixed> */
    private static function decodeFrame(string $frame): array
    {
        $event = Coerce::stringKeyedArray(json_decode(substr($frame, 6, -2), true, 512, JSON_THROW_ON_ERROR));
        if ($event === null) {
            self::fail('expected the frame payload to decode to a JSON object');
        }

        return $event;
    }

    /** @return list<string> */
    private static function types(RecordingSink $sink): array
    {
        /** @var list<string> */
        return array_column(self::decode($sink), 'type');
    }

    /**
     * Fetches a value at a key/index, failing loudly rather than returning a
     * possibly-undefined value. Shared by every guard clause below so the
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
