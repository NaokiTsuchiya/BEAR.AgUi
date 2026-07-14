<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Integration\ExampleBear;

use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Llm\StreamEvent;
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
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-w', 'name' => 'weather_get']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"city":"Tokyo"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-n', 'name' => 'news_get']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"topic":"php"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'summary']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        CoroutineTestRunner::run(function () use ($llm): void {
            $events = $this->drainRun($llm, 'weather and news please');

            static::assertInstanceOf(RunStarted::class, $events[0]);

            // Both TOOL_CALL_* groups carry the real wire ids (registry
            // id-keying, tasks-parallel T1) and the REAL resource bodies —
            // the resource-driven Dispatcher hit Weather and News.
            $starts = $this->ofType($events, ToolCallStart::class);
            static::assertCount(2, $starts);
            $start0 = $starts[0];
            static::assertInstanceOf(ToolCallStart::class, $start0);
            static::assertSame('call-w', $start0->toolCallId);
            static::assertSame('weather_get', $start0->toolCallName);
            $start1 = $starts[1];
            static::assertInstanceOf(ToolCallStart::class, $start1);
            static::assertSame('call-n', $start1->toolCallId);

            $results = $this->ofType($events, ToolCallResult::class);
            static::assertCount(2, $results);
            $result0 = $results[0];
            static::assertInstanceOf(ToolCallResult::class, $result0);
            static::assertSame('call-w', $result0->toolCallId);
            static::assertStringContainsString('"condition":"sunny"', $result0->content);
            $result1 = $results[1];
            static::assertInstanceOf(ToolCallResult::class, $result1);
            static::assertSame('call-n', $result1->toolCallId);
            static::assertStringContainsString('"headline"', $result1->content);

            $finished = $events[count($events) - 1];
            static::assertInstanceOf(RunFinished::class, $finished);
            static::assertSame('success', $this->outcomeType($finished));
        });
    }

    public function testConfirmableReminderInterruptsRun(): void
    {
        $llm = new FakeStreamingLlmClient();
        $llm->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Saving a reminder needs your approval.']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-r', 'name' => 'reminder_put']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"id":"r-1","text":"buy milk"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);

        $events = $this->drainRun($llm, 'remind me to buy milk');

        $finished = $events[count($events) - 1];
        static::assertInstanceOf(RunFinished::class, $finished);
        static::assertSame('interrupt', $this->outcomeType($finished));

        $decoded = json_decode(json_encode($finished, JSON_THROW_ON_ERROR), true);
        static::assertIsArray($decoded);
        $outcome = $decoded['outcome'];
        static::assertIsArray($outcome);
        $interrupts = $outcome['interrupts'];
        static::assertIsArray($interrupts);
        $interrupt0 = $interrupts[0];
        static::assertIsArray($interrupt0);
        static::assertSame('tool_confirmation', $interrupt0['reason']);
        static::assertSame('call-r', $interrupt0['toolCallId']);
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
        $request0 = $llm->requests[0];
        static::assertNotNull($request0);
        $offered = array_map(static fn(Tool $tool): string => $tool->name, $request0['tools']);
        static::assertSame(
            ['weather_get', 'news_get', 'reminder_put', 'package_search', 'word_similarity_get', 'rot13_get'],
            $offered,
        );

        // The refused call is fed back to the model as an error tool_result
        // — the Message resource itself was never dispatched.
        $feedback = json_encode($llm->requests[1]['messages'], JSON_THROW_ON_ERROR);
        static::assertStringContainsString('Tool is not enabled: message_post', $feedback);

        $results = $this->ofType($events, ToolCallResult::class);
        static::assertCount(1, $results);
        $result0 = $results[0];
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

        static::assertInstanceOf(RunStarted::class, $events[0]);
        $last = $events[count($events) - 1];
        static::assertInstanceOf(RunError::class, $last);
        static::assertSame('AGENT_ERROR', $last->code);
        static::assertSame('Internal agent error.', $last->message);
    }

    public function testBrokenJsonYieldsValidationErrorArrayBody(): void
    {
        $ro = $this->invocations()->post('page://self/invocations', ['rawBody' => '{not json']);

        static::assertSame(400, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('VALIDATION_ERROR', $ro->body['code']);
        static::assertNotSame([], $ro->body['errors']);
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
        static::assertSame('VALIDATION_ERROR', $ro->body['code']);
    }

    public function testPingReportsHealthy(): void
    {
        $ro = ExampleBearInjectorFactory::app()->getInstance(ResourceInterface::class)->get('page://self/ping');

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('Healthy', $ro->body['status']);
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
        $decoded = json_decode(json_encode($finished, JSON_THROW_ON_ERROR), true);
        static::assertIsArray($decoded);
        $outcome = $decoded['outcome'];
        static::assertIsArray($outcome);

        return (string) $outcome['type'];
    }
}
