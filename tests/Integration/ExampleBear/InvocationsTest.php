<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Integration\ExampleBear;

use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallResult;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallStart;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\Support\CoroutineTestRunner;
use NaokiTsuchiya\BEARAgUi\Support\ExampleBearInjectorFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_map;
use function assert;
use function count;
use function is_iterable;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * In-process integration of the BEAR showcase app (tasks-m3 T10, D22 — no
 * HTTP): the Invocations page resource is driven through a real Injector
 * with only the LLM boundary faked. The real resource-driven Dispatcher
 * executes the real #[Tool] resources (resource-as-tool), the ALPS policy
 * governs exposure, and the parallel agent fans plain calls out on Swoole
 * coroutines.
 *
 * @mago-expect lint:too-many-methods
 *
 * One method per AG-UI scenario (parallel / interrupt / governance /
 * error dichotomy), same convention as AgUiRunnerTest.
 */
#[CoversNothing]
final class InvocationsTest extends TestCase
{
    /** @throws Throwable */
    #[RequiresPhpExtension('swoole')]
    public function testParallelRunPairsToolEventsByIdAndCarriesResourceBodies(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-r', 'name' => 'rot13_get']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"text":"BEAR Sunday"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-s', 'name' => 'word_similarity_get']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"a":"PHP","b":"PHP8"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'summary']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        CoroutineTestRunner::run(function () use ($llm): void {
            $events = $this->drainRun($llm, 'rot13 and similarity please');

            static::assertInstanceOf(RunStarted::class, $this->eventAt($events, 0));

            // Both TOOL_CALL_* groups carry the real wire ids (registry
            // id-keying, tasks-parallel T1) and the REAL resource bodies —
            // the resource-driven Dispatcher hit Rot13 and Similarity.
            $starts = $this->ofType($events, ToolCallStart::class);
            static::assertCount(2, $starts);
            $start0 = $this->eventAt($starts, 0);
            static::assertInstanceOf(ToolCallStart::class, $start0);
            static::assertSame('call-r', $start0->toolCallId);
            static::assertSame('rot13_get', $start0->toolCallName);
            $start1 = $this->eventAt($starts, 1);
            static::assertInstanceOf(ToolCallStart::class, $start1);
            static::assertSame('call-s', $start1->toolCallId);

            $results = $this->ofType($events, ToolCallResult::class);
            static::assertCount(2, $results);
            $result0 = $this->eventAt($results, 0);
            static::assertInstanceOf(ToolCallResult::class, $result0);
            static::assertSame('call-r', $result0->toolCallId);
            static::assertStringContainsString('"output":"ORNE Fhaqnl"', $result0->content);
            $result1 = $this->eventAt($results, 1);
            static::assertInstanceOf(ToolCallResult::class, $result1);
            static::assertSame('call-s', $result1->toolCallId);
            static::assertStringContainsString('"similarity_percent"', $result1->content);

            $finished = $this->eventAt($events, count($events) - 1);
            static::assertInstanceOf(RunFinished::class, $finished);
            static::assertSame('success', $this->outcomeType($finished));
        });
    }

    public function testUnsafeMessagePostIsGovernedAwayByAlpsPolicy(): void
    {
        // The canned model tries to call message_post anyway; the ALPS
        // policy removed it from the request tools, so the agent refuses
        // the call without ever dispatching the Message resource.
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-m', 'name' => 'message_post']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"to":"alice","body":"hi"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'I cannot send messages.']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        $events = $this->drainRun($llm, 'message alice for me');

        // The governance itself: the tools offered to the LLM never
        // included message_post (stripped by safeAndIdempotent).
        $request0 = $this->requestAt($llm, 0);
        $offered = array_map(static fn(Tool $tool): string => $tool->name, $request0['tools']);
        static::assertSame(
            [
                'package_search',
                'word_similarity_get',
                'rot13_get',
                'sun_info_get',
            ],
            $offered,
        );

        // The refused call is fed back to the model as an error tool_result
        // — the Message resource itself was never dispatched.
        $request1 = $this->requestAt($llm, 1);
        $feedback = json_encode($request1['messages'], JSON_THROW_ON_ERROR);
        static::assertStringContainsString('Tool is not enabled: message_post', $feedback);

        $results = $this->ofType($events, ToolCallResult::class);
        static::assertCount(1, $results);
        $result0 = $this->eventAt($results, 0);
        static::assertInstanceOf(ToolCallResult::class, $result0);
        static::assertSame('call-m', $result0->toolCallId);
    }

    public function testMidRunFailureSurfacesAsRunErrorOnTheOpenStream(): void
    {
        // No scripted turns: the LLM boundary throws once the host starts
        // draining — after RUN_STARTED already went out on the open 200
        // stream, so the failure must be a RUN_ERROR frame (D11), never
        // an exception or a status change.
        $events = $this->drainRun(new FakeStreamingLlmClient(), 'hello');

        static::assertInstanceOf(RunStarted::class, $this->eventAt($events, 0));
        $last = $this->eventAt($events, count($events) - 1);
        static::assertInstanceOf(RunError::class, $last);
        static::assertSame('AGENT_ERROR', $last->code);
        static::assertSame('Internal agent error.', $last->message);
    }

    public function testBrokenJsonYieldsValidationErrorArrayBody(): void
    {
        $ro = $this->invocations()->post('page://self/invocations', ['rawBody' => '{not json']);

        static::assertSame(400, $ro->code);
        static::assertIsArray($ro->body);

        /** @var mixed $code */
        $code = $ro->body['code'] ?? null;
        static::assertSame('VALIDATION_ERROR', $code);

        /** @var mixed $errors */
        $errors = $ro->body['errors'] ?? null;
        static::assertNotSame([], $errors);
    }

    public function testEmptyUserContentYieldsValidationError(): void
    {
        $body = json_encode([
            'threadId' => 't-1',
            'runId' => 'r-1',
            'messages' => [['id' => 'm-1', 'role' => 'user', 'content' => '']],
        ], JSON_THROW_ON_ERROR);

        $ro = $this->invocations()->post('page://self/invocations', ['rawBody' => $body]);

        static::assertSame(400, $ro->code);
        static::assertIsArray($ro->body);

        /** @var mixed $code */
        $code = $ro->body['code'] ?? null;
        static::assertSame('VALIDATION_ERROR', $code);
    }

    public function testPingReportsHealthy(): void
    {
        $ro = ExampleBearInjectorFactory::app()->getInstance(ResourceInterface::class)->get('page://self/ping');

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);

        /** @var mixed $status */
        $status = $ro->body['status'] ?? null;
        static::assertSame('Healthy', $status);
    }

    private function invocations(): ResourceInterface
    {
        return ExampleBearInjectorFactory::withLlm(new FakeStreamingLlmClient())->getInstance(ResourceInterface::class);
    }

    /**
     * POST the standard minimal AG-UI body and drain the lazy event stream.
     *
     * @return list<AgUiEventInterface>
     */
    private function drainRun(FakeStreamingLlmClient $llm, string $userMessage): array
    {
        $resource = ExampleBearInjectorFactory::withLlm($llm)->getInstance(ResourceInterface::class);
        $body = json_encode([
            'threadId' => 't-1',
            'runId' => 'r-1',
            'messages' => [['id' => 'm-1', 'role' => 'user', 'content' => $userMessage]],
        ], JSON_THROW_ON_ERROR);

        $ro = $resource->post('page://self/invocations', ['rawBody' => $body]);
        static::assertSame(200, $ro->code);
        assert(is_iterable($ro->body), 'Resource response body must be iterable to drain SSE events.');

        $events = [];
        /** @var mixed $event */
        foreach ($ro->body as $event) {
            static::assertInstanceOf(AgUiEventInterface::class, $event);
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param list<AgUiEventInterface> $events
     * @param class-string<T>          $class
     *
     * @return list<T>
     *
     * @template T of AgUiEventInterface
     */
    private function ofType(array $events, string $class): array
    {
        $matched = [];
        foreach ($events as $event) {
            if (!$event instanceof $class) {
                continue;
            }

            $matched[] = $event;
        }

        return $matched;
    }

    private function outcomeType(RunFinished $finished): string
    {
        $decoded = self::decodeJsonObject($finished);

        /** @var mixed $rawOutcome */
        $rawOutcome = $this->valueAt($decoded, 'outcome');
        static::assertIsArray($rawOutcome);

        /** @var mixed $rawType */
        $rawType = $this->valueAt($rawOutcome, 'type');
        static::assertIsString($rawType);

        return $rawType;
    }

    /** @return array<array-key, mixed> */
    private static function decodeJsonObject(RunFinished $finished): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode(json_encode($finished, JSON_THROW_ON_ERROR), true);
        static::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Narrow `$events[$index]` in one place instead of duplicating the
     * lookup at every call site — callers keep asserting the concrete
     * event type themselves (`assertInstanceOf`), which already fails the
     * same way when the index is missing.
     *
     * @param list<AgUiEventInterface> $events
     */
    private function eventAt(array $events, int $index): AgUiEventInterface|null
    {
        return $events[$index] ?? null;
    }

    /**
     * Narrow `$array[$key] ?? null` in one place instead of duplicating the
     * lookup at every call site.
     *
     * @param array<array-key, mixed> $array
     */
    private function valueAt(array $array, int|string $key): mixed
    {
        return $array[$key] ?? null;
    }

    /**
     * The nth captured LLM request, asserted present.
     *
     * @return array{system: string, messages: list<Message>, tools: list<Tool>}
     */
    private function requestAt(FakeStreamingLlmClient $llm, int $index): array
    {
        $request = $llm->requests[$index] ?? null;
        self::assertNotNull($request);

        return $request;
    }
}
