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
        $messages = $captured[0];
        static::assertNotNull($messages);
        static::assertCount(3, $messages);
        static::assertNotNull($messages[0]);
        static::assertNotNull($messages[1]);
        static::assertNotNull($messages[2]);
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
        $input = self::input('hi', [], ['search', 'browser']);
        self::render(self::runner($llm, $dispatcher, [self::tool('search')]), $input, $sink);

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

        $input = self::input('do it', [], ['writer']);
        self::render(self::runner($llm, $dispatcher, [self::confirmableTool('writer')]), $input, $sink);

        $events = self::decode($sink);
        $finished = $events[array_key_last($events)];
        static::assertNotNull($finished);
        static::assertSame('RUN_FINISHED', $finished['type']);

        $outcome = $finished['outcome'];
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

    public function testRuntimeFailureMidStreamSurfacesAsRunErrorOver200(): void
    {
        // No scripted runs queued: the LLM throws when the agent pulls its
        // first stream — a mid-stream failure, distinct from a pre-flight
        // 400. The stream is already open at 200 by then.
        $sink = new RecordingSink();

        self::render(self::runner(new FakeStreamingLlmClient(), new FakeDispatcher(), []), self::input('hi'), $sink);

        static::assertSame('RUN_ERROR', self::types($sink)[array_key_last(self::types($sink))]);
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
        return array_map(static function (string $frame): array {
            $decoded = json_decode(substr($frame, 6, -2), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            $event = [];
            foreach ($decoded as $key => $value) {
                self::assertIsString($key);
                $event[$key] = $value;
            }

            return $event;
        }, $sink->frames);
    }

    /** @return list<string> */
    private static function types(RecordingSink $sink): array
    {
        /** @var list<string> */
        return array_column(self::decode($sink), 'type');
    }
}
