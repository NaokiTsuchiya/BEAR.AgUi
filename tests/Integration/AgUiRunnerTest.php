<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Integration;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\Message as ToolUseMessage;
use BEAR\ToolUse\Schema\Tool as SchemaTool;
use Generator;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInput;
use NaokiTsuchiya\BEARAgUi\Input\Tool as InputTool;
use NaokiTsuchiya\BEARAgUi\Sse\SseEncoder;
use NaokiTsuchiya\BEARAgUi\Support\RecordingSink;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use NaokiTsuchiya\BEARAgUi\ToolUse\StreamingAgentFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function array_column;
use function array_map;
use function json_decode;
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

        $result = self::runner($llm, new FakeDispatcher(), [])->run(self::input([new UserMessage('m1', 'hi')]), $sink);

        static::assertNull($result);
        static::assertSame([200], $sink->opens);
        static::assertSame(1, $sink->closes);
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

        $input = self::input([
            new UserMessage('m1', 'first question'),
            new AssistantMessage('m2', 'first answer', []),
            new UserMessage('m3', 'follow up'),
        ]);
        self::runner($llm, new FakeDispatcher(), [])->run($input, new RecordingSink());

        // The agent saw the seeded history followed by the new user turn.
        $messages = $captured[0];
        static::assertCount(3, $messages);
        static::assertSame('user', $messages[0]->role);
        static::assertSame([['type' => 'text', 'text' => 'first question']], $messages[0]->content);
        static::assertSame('assistant', $messages[1]->role);
        static::assertSame([['type' => 'text', 'text' => 'first answer']], $messages[1]->content);
        static::assertSame('user', $messages[2]->role);
        static::assertSame([['type' => 'text', 'text' => 'follow up']], $messages[2]->content);
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
        $input = self::input([new UserMessage('m1', 'hi')], [
            new InputTool('search', '', []),
            new InputTool('browser', '', []),
        ]);
        $result = self::runner($llm, $dispatcher, [self::tool('search')])->run($input, $sink);

        static::assertNull($result);
        $types = self::types($sink);
        static::assertContains('TOOL_CALL_RESULT', $types);
        static::assertNotContains('RUN_ERROR', $types);
        static::assertSame('RUN_FINISHED', $types[array_key_last($types)]);
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

        $input = self::input([new UserMessage('m1', 'do it')], [new InputTool('writer', '', [])]);
        self::runner($llm, $dispatcher, [self::confirmableTool('writer')])->run($input, $sink);

        $events = self::decode($sink);
        $finished = $events[array_key_last($events)];
        static::assertSame('RUN_FINISHED', $finished['type']);
        static::assertSame('interrupt', $finished['outcome']['type']);
        static::assertSame('tool_confirmation', $finished['outcome']['interrupts'][0]['reason']);
        static::assertSame('call-1', $finished['outcome']['interrupts'][0]['toolCallId']);
        static::assertCount(0, $dispatcher->calls);
    }

    public function testRuntimeFailureMidStreamSurfacesAsRunErrorOver200(): void
    {
        // No scripted runs queued: the LLM throws when the agent pulls its
        // first stream — a mid-stream failure, distinct from a pre-flight
        // 400. The stream is already open at 200 by then.
        $sink = new RecordingSink();

        $result = self::runner(new FakeStreamingLlmClient(), new FakeDispatcher(), [])->run(
            self::input([new UserMessage('m1', 'hi')]),
            $sink,
        );

        static::assertNull($result);
        static::assertSame([200], $sink->opens);
        static::assertSame(1, $sink->closes);
        static::assertSame('RUN_ERROR', self::types($sink)[array_key_last(self::types($sink))]);
    }

    public function testEmptyUserContentReturnsParseErrorWithoutOpeningSink(): void
    {
        $sink = new RecordingSink();

        $result = self::runner(new FakeStreamingLlmClient(), new FakeDispatcher(), [])->run(
            self::input([new UserMessage('m1', '')]),
            $sink,
        );

        static::assertInstanceOf(ParseError::class, $result);
        static::assertSame([], $sink->opens);
        static::assertSame([], $sink->frames);
        static::assertSame(0, $sink->closes);
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
            new SseEncoder(),
            new NullLogger(),
            [],
        );
    }

    /** @param list<Message> $messages */
    private static function input(array $messages, array $tools = []): RunAgentInput
    {
        return new RunAgentInput('t', 'r', $messages, $tools, [], null, [], []);
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
        return array_map(
            static fn(string $frame): array => (array) json_decode(
                substr($frame, 6, -2),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
            $sink->frames,
        );
    }

    /** @return list<string> */
    private static function types(RecordingSink $sink): array
    {
        /** @var list<string> $types */
        $types = array_column(self::decode($sink), 'type');

        return $types;
    }
}
