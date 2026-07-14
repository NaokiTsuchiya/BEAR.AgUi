<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Integration\Example;

use BEAR\ToolUse\Llm\StreamEvent;
use Example\Server\Tool\AskConfirmationTool;
use Example\Server\Tool\GetTimeTool;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInputParser;
use NaokiTsuchiya\BEARAgUi\Sse\SseEncoder;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use NaokiTsuchiya\BEARAgUi\Support\JsonFixture;
use NaokiTsuchiya\BEARAgUi\Support\RecordingSink;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use NaokiTsuchiya\BEARAgUi\ToolUse\StreamingAgentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_column;
use function array_key_last;
use function array_map;
use function array_slice;
use function json_decode;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * In-process integration tests of the example server's full host path
 * (T7, D22): raw JSON body → {@see RunAgentInputParser} → {@see AgUiRunner}
 * → {@see SseResponder} → recorded SSE frames — exactly the pipeline
 * `example/server/public/index.php` drives over HTTP, minus the SAPI.
 *
 * The wiring mirrors `Example\Server\Bootstrap` (demo tools, system prompt,
 * responder) except at the LLM / dispatcher boundary, where the scripted
 * fakes keep every run deterministic (per T7 the OpenAI conversion layer is
 * covered by its own unit tests; the runner is built inline so example/
 * never depends on tests/).
 *
 * @mago-expect lint:too-many-methods
 *
 * Complements {@see \NaokiTsuchiya\BEARAgUi\Integration\AgUiRunnerTest}
 * (runner-level scenarios on a hand-built RunAgentInput): here every input
 * comes from a wire-shape fixture through the real parser, and validation
 * failures are asserted to reject the body before any run starts.
 */
#[CoversClass(AgUiRunner::class)]
#[CoversClass(RunAgentInputParser::class)]
#[CoversClass(SseResponder::class)]
final class ExampleRunnerTest extends TestCase
{
    /** @throws RuntimeException */
    public function testSingleTextTurnStreamsLifecycleFromWireBodyToSseFrames(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Hello from the example agent.']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);
        $sink = new RecordingSink();

        $errors = self::host(JsonFixture::load('Input/minimal.json'), self::runner($llm, new FakeDispatcher()), $sink);

        static::assertSame([], $errors);
        static::assertSame('text/event-stream', $sink->headers['Content-Type']);
        static::assertSame(
            ['RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END', 'RUN_FINISHED'],
            self::types($sink),
        );

        // Run correlation comes from the parsed wire body, and the terminal
        // frame carries the success outcome.
        $events = self::decode($sink);
        static::assertSame('t-1', $events[0]['threadId']);
        static::assertSame('r-1', $events[0]['runId']);
        $outcome = $events[array_key_last($events)]['outcome'];
        static::assertIsArray($outcome);
        static::assertSame('success', $outcome['type']);
    }

    /** @throws RuntimeException */
    public function testToolLoopPairsCallFramesAndMintsANewMessageIdForFinalText(): void
    {
        $llm = new FakeStreamingLlmClient();
        // Iteration 1: text, then the LLM asks for the demo get_time tool
        // with its arguments split across two deltas.
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Checking the clock.']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => GetTimeTool::NAME]),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"timezone":']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '"UTC"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        // Iteration 2: after the tool result, the LLM finalizes.
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'It is 2026-07-08T09:00:00+00:00.']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);
        $dispatcher = new FakeDispatcher();
        $dispatcher->queueSuccess(GetTimeTool::NAME, '2026-07-08T09:00:00+00:00');
        $sink = new RecordingSink();

        $errors = self::host(JsonFixture::load('Input/minimal.json'), self::runner($llm, $dispatcher), $sink);

        static::assertSame([], $errors);
        // The lone extra TEXT_MESSAGE_CONTENT after the tool round is the
        // "\n" separator the agent emits between a text-then-tool turn and
        // the next turn's text.
        static::assertSame(
            [
                'RUN_STARTED',
                'TEXT_MESSAGE_START',
                'TEXT_MESSAGE_CONTENT',
                'TEXT_MESSAGE_END',
                'TOOL_CALL_START',
                'TOOL_CALL_ARGS',
                'TOOL_CALL_END',
                'TOOL_CALL_RESULT',
                'TEXT_MESSAGE_START',
                'TEXT_MESSAGE_CONTENT',
                'TEXT_MESSAGE_CONTENT',
                'TEXT_MESSAGE_END',
                'RUN_FINISHED',
            ],
            self::types($sink),
        );

        // The whole tool exchange is correlated by the LLM-issued call id.
        $events = self::decode($sink);
        static::assertSame(GetTimeTool::NAME, $events[4]['toolCallName']);
        static::assertSame(
            ['call-1', 'call-1', 'call-1', 'call-1'],
            array_column(self::slice($events, 4, 4), 'toolCallId'),
        );
        static::assertSame('{"timezone":"UTC"}', $events[5]['delta']);
        static::assertSame('2026-07-08T09:00:00+00:00', $events[7]['content']);

        // The post-tool text opens a NEW assistant message (D9/D10).
        static::assertNotSame($events[1]['messageId'], $events[8]['messageId']);
    }

    /** @throws RuntimeException */
    public function testConfirmableToolFinishesWithInterruptOutcome(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-9', 'name' => AskConfirmationTool::NAME]),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"message":"Proceed?"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        // No second script: the run must stop at the confirmation boundary.
        $dispatcher = new FakeDispatcher();
        $sink = new RecordingSink();

        $errors = self::host(JsonFixture::load('Input/minimal.json'), self::runner($llm, $dispatcher), $sink);

        static::assertSame([], $errors);
        static::assertSame(['RUN_STARTED', 'TOOL_CALL_START', 'RUN_FINISHED'], self::types($sink));

        $events = self::decode($sink);
        $finished = $events[array_key_last($events)];
        $outcome = $finished['outcome'];
        static::assertIsArray($outcome);
        static::assertSame('interrupt', $outcome['type']);

        $interrupts = $outcome['interrupts'];
        static::assertIsArray($interrupts);
        $interrupt = $interrupts[0];
        static::assertIsArray($interrupt);
        static::assertSame('tool_confirmation', $interrupt['reason']);
        static::assertSame('call-9', $interrupt['toolCallId']);
        static::assertCount(0, $dispatcher->calls);
    }

    /** @throws RuntimeException */
    public function testMidRunFailureSurfacesAsRunErrorOnTheOpenStream(): void
    {
        // No scripted runs: the LLM throws on the first pull, after the
        // stream is already open — a 200-level concern, framed as RUN_ERROR.
        $sink = new RecordingSink();

        $errors = self::host(
            JsonFixture::load('Input/minimal.json'),
            self::runner(new FakeStreamingLlmClient(), new FakeDispatcher()),
            $sink,
        );

        static::assertSame([], $errors);
        static::assertSame(['RUN_STARTED', 'RUN_ERROR'], self::types($sink));

        $events = self::decode($sink);
        static::assertSame('Internal agent error.', $events[1]['message']);
        static::assertSame('AGENT_ERROR', $events[1]['code']);
    }

    /** @throws RuntimeException */
    public function testBrokenJsonIsRejectedBeforeAnyRun(): void
    {
        $llm = new FakeStreamingLlmClient();
        $sink = new RecordingSink();

        $errors = self::host(
            JsonFixture::load('Input/broken-json.json'),
            self::runner($llm, new FakeDispatcher()),
            $sink,
        );

        static::assertSame(['Invalid JSON: Syntax error'], self::messages($errors));
        static::assertSame([], $sink->frames);
        static::assertSame(0, $llm->callCount);
    }

    /** @throws RuntimeException */
    public function testEmptyUserContentIsRejectedBeforeAnyRun(): void
    {
        $llm = new FakeStreamingLlmClient();
        $sink = new RecordingSink();

        $errors = self::host(
            JsonFixture::load('Input/empty-user-content.json'),
            self::runner($llm, new FakeDispatcher()),
            $sink,
        );

        static::assertSame(['messages[] must contain a user message with text content.'], self::messages($errors));
        static::assertSame([], $sink->frames);
        static::assertSame(0, $llm->callCount);
    }

    /**
     * Play the front controller without the SAPI: parse the raw body, and
     * only when it is run-ready stream the run through the responder into
     * the sink (the err side maps to HTTP 400 — no frames are ever built).
     *
     * @return list<ParseError> the parse errors; [] when the run streamed
     */
    private static function host(string $body, AgUiRunner $runner, RecordingSink $sink): array
    {
        $parsed = (new RunAgentInputParser())->parse($body);
        if (!$parsed->isOk()) {
            return $parsed->unwrapErr();
        }

        (new SseResponder(new SseEncoder()))->respond($runner->stream($parsed->unwrap()), $sink);

        return [];
    }

    /** Bootstrap's wiring with the fakes swapped in at the LLM / dispatcher boundary (D22). */
    private static function runner(FakeStreamingLlmClient $llm, FakeDispatcher $dispatcher): AgUiRunner
    {
        $getTime = new GetTimeTool();

        return new AgUiRunner(
            new StreamingAgentFactory(
                $llm,
                $dispatcher,
                [$getTime->definition(), (new AskConfirmationTool())->definition()],
                'You are a helpful assistant. Use the provided tools when relevant.',
            ),
            new MessageHistoryMapper(),
            new AgUiAdapter(new NullLogger()),
            [],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decode(RecordingSink $sink): array
    {
        return array_map(self::decodeFrame(...), $sink->frames);
    }

    /**
     * Decode a single SSE `data:` frame into its JSON-object payload,
     * proving the string-keyed shape the fixtures always produce rather
     * than trusting the blind `(array)` cast on `json_decode()`'s `mixed`.
     *
     * @return array<string, mixed>
     */
    private static function decodeFrame(string $frame): array
    {
        $decoded = json_decode(substr($frame, 6, -2), true, 512, JSON_THROW_ON_ERROR);
        static::assertIsArray($decoded);

        $payload = [];
        foreach ($decoded as $key => $value) {
            static::assertIsString($key);
            $payload[$key] = $value;
        }

        return $payload;
    }

    /** @return list<string> */
    private static function types(RecordingSink $sink): array
    {
        /** @var list<string> */
        return array_column(self::decode($sink), 'type');
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function slice(array $events, int $offset, int $length): array
    {
        return array_slice($events, $offset, $length);
    }

    /**
     * @param list<ParseError> $errors
     *
     * @return list<string>
     */
    private static function messages(array $errors): array
    {
        return array_map(static fn(ParseError $error): string => $error->message, $errors);
    }
}
