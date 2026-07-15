<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Runtime;

use BEAR\ToolUse\Runtime\AgentEvent;
use BEAR\ToolUse\Runtime\StreamingAgent;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\Fake\OverlapProbeDispatcher;
use NaokiTsuchiya\BEARAgUi\Support\CoroutineTestRunner;
use NaokiTsuchiya\BEARAgUi\Support\ParallelAgentScenarioFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;
use function iterator_to_array;

#[CoversClass(ParallelStreamingAgent::class)]
#[RequiresPhpExtension('swoole')]
final class ParallelStreamingAgentTest extends TestCase
{
    use ParallelAgentScenarioFixture;

    /** @throws Throwable */
    public function testPlainToolsRunConcurrentlyAndKeepEventOrder(): void
    {
        CoroutineTestRunner::run(function (): void {
            $llm = new FakeStreamingLlmClient();
            $llm->queueScript($this->toolTurn([
                ['call-a', 'alpha', '{"p":1}'],
                ['call-b', 'beta',  '{"q":2}'],
                ['call-c', 'gamma', '{"r":3}'],
            ]));
            $llm->queueScript($this->finalTurn('done'));

            $probe = new OverlapProbeDispatcher();
            $agent = new ParallelStreamingAgent(
                $llm,
                $probe,
                [$this->tool('alpha'), $this->tool('beta'), $this->tool('gamma')],
                '',
            );

            $events = iterator_to_array($agent->runStream('go'), false);

            // All three plain calls were in flight at once.
            static::assertSame(3, $probe->maxActive);

            static::assertSame(
                [
                    AgentEvent::TEXT_DELTA,
                    AgentEvent::TOOL_START,
                    AgentEvent::TOOL_START,
                    AgentEvent::TOOL_START,
                    AgentEvent::TOOL_RESULT,
                    AgentEvent::TOOL_RESULT,
                    AgentEvent::TOOL_RESULT,
                    AgentEvent::TEXT_DELTA,
                    AgentEvent::TEXT_DELTA,
                    AgentEvent::COMPLETED,
                ],
                $this->typesOf($events),
            );

            // tool_result events come in pending (wire) order even though
            // completion order under the scheduler is arbitrary. Narrow
            // once via a local closure instead of repeating the existence
            // guard at each index.
            $toolNameAt = static function (int $index) use ($events): mixed {
                $event = $events[$index] ?? null;
                self::assertNotNull($event);

                return $event->data['toolName'] ?? null;
            };

            static::assertSame(['alpha', 'beta', 'gamma'], [
                $toolNameAt(4),
                $toolNameAt(5),
                $toolNameAt(6),
            ]);

            // Tool results are fed back to the LLM in pending order.
            $toolResults = $this->toolResultsContent($agent);
            static::assertSame(['call-a', 'call-b', 'call-c'], array_map($this->toolUseId(...), $toolResults));
        });
    }

    /** @throws Throwable */
    public function testEventSequenceMatchesSequentialAgentWithoutConfirmations(): void
    {
        CoroutineTestRunner::run(function (): void {
            $tools = [$this->tool('alpha'), $this->tool('beta')];
            $turn1 = [
                ['call-a', 'alpha', '{"p":1}'],
                ['call-b', 'beta',  '{"q":2}'],
            ];

            $parallelLlm = new FakeStreamingLlmClient();
            $parallelLlm->queueScript($this->toolTurn($turn1));
            $parallelLlm->queueScript($this->finalTurn('done'));
            $parallelProbe = new OverlapProbeDispatcher();
            $parallel = new ParallelStreamingAgent($parallelLlm, $parallelProbe, $tools, '');

            $sequentialLlm = new FakeStreamingLlmClient();
            $sequentialLlm->queueScript($this->toolTurn($turn1));
            $sequentialLlm->queueScript($this->finalTurn('done'));
            $sequentialProbe = new OverlapProbeDispatcher();
            $sequential = new StreamingAgent($sequentialLlm, $sequentialProbe, $tools, '');

            $parallelEvents = $this->encodeAll($parallel->runStream('go'));
            $sequentialEvents = $this->encodeAll($sequential->runStream('go'));

            static::assertSame($sequentialEvents, $parallelEvents);
            static::assertSame(1, $sequentialProbe->maxActive);
            static::assertSame(2, $parallelProbe->maxActive);
        });
    }

    /** @throws Throwable */
    public function testDeniedConfirmationCancelsWithoutDispatch(): void
    {
        CoroutineTestRunner::run(function (): void {
            $llm = new FakeStreamingLlmClient();
            $llm->queueScript($this->toolTurn([
                ['call-a', 'alpha',  '{"p":1}'],
                ['call-d', 'danger', '{"x":9}'],
            ]));
            $llm->queueScript($this->finalTurn('done'));

            $probe = new OverlapProbeDispatcher();
            $agent = new ParallelStreamingAgent(
                $llm,
                $probe,
                [$this->tool('alpha'), $this->confirmableTool('danger')],
                '',
            );

            $events = $this->drive($agent->runStream('go'), approval: false);

            static::assertContains(AgentEvent::CONFIRMATION_REQUIRED, $this->typesOf($events));
            // Denied tool is never dispatched; the plain one still runs.
            static::assertSame(['alpha'], $probe->dispatchedNames);

            $toolResults = $this->toolResultsContent($agent);
            $toolResult0 = $this->toolResultBlock($toolResults, 0);
            $toolResult1 = $this->toolResultBlock($toolResults, 1);
            static::assertFalse($toolResult0['is_error'] ?? null);
            static::assertTrue($toolResult1['is_error'] ?? null);
            static::assertSame('User cancelled this operation.', $toolResult1['content'] ?? null);
        });
    }

    /** @throws Throwable */
    public function testApprovedConfirmableRunsSeriallyBeforeParallelWave(): void
    {
        CoroutineTestRunner::run(function (): void {
            $llm = new FakeStreamingLlmClient();
            $llm->queueScript($this->toolTurn([
                ['call-a', 'alpha',  '{"p":1}'],
                ['call-d', 'danger', '{"x":9}'],
            ]));
            $llm->queueScript($this->finalTurn('done'));

            $probe = new OverlapProbeDispatcher();
            $agent = new ParallelStreamingAgent(
                $llm,
                $probe,
                [$this->tool('alpha'), $this->confirmableTool('danger')],
                '',
            );

            $events = $this->drive($agent->runStream('go'), approval: true);

            // Approved confirmable executed serially in pass 1, before the
            // parallel wave dispatched the plain call.
            static::assertSame(['danger', 'alpha'], $probe->dispatchedNames);
            static::assertSame(1, $probe->maxActive);

            // Results and result events stay in pending order.
            static::assertSame(['alpha', 'danger'], $this->toolResultNames($events));

            $toolResults = $this->toolResultsContent($agent);
            static::assertSame(['call-a', 'call-d'], array_map($this->toolUseId(...), $toolResults));
        });
    }

    /** @throws Throwable */
    public function testUnknownToolErrorsWithoutDispatchAndKeepsOrder(): void
    {
        CoroutineTestRunner::run(function (): void {
            $llm = new FakeStreamingLlmClient();
            $llm->queueScript($this->toolTurn([
                ['call-g', 'ghost', '{}'],
                ['call-a', 'alpha', '{"p":1}'],
            ]));
            $llm->queueScript($this->finalTurn('done'));

            $probe = new OverlapProbeDispatcher();
            $agent = new ParallelStreamingAgent($llm, $probe, [$this->tool('alpha')], '');

            $events = iterator_to_array($agent->runStream('go'), false);

            static::assertSame(['alpha'], $probe->dispatchedNames);

            static::assertSame(['ghost', 'alpha'], $this->toolResultNames($events));

            $toolResults = $this->toolResultsContent($agent);
            $toolResult0 = $this->toolResultBlock($toolResults, 0);
            $toolResult1 = $this->toolResultBlock($toolResults, 1);
            static::assertTrue($toolResult0['is_error'] ?? null);
            static::assertSame('Tool is not enabled: ghost', $toolResult0['content'] ?? null);
            static::assertFalse($toolResult1['is_error'] ?? null);
        });
    }

    /** @throws Throwable */
    public function testThrowingDispatcherBecomesErrorResult(): void
    {
        CoroutineTestRunner::run(function (): void {
            $llm = new FakeStreamingLlmClient();
            $llm->queueScript($this->toolTurn([['call-a', 'alpha', '{"p":1}']]));
            $llm->queueScript($this->finalTurn('done'));

            $dispatcher = new FakeDispatcher();
            $dispatcher->queueThrow('alpha', new RuntimeException('boom'));

            $agent = new ParallelStreamingAgent($llm, $dispatcher, [$this->tool('alpha')], '');

            iterator_to_array($agent->runStream('go'), false);

            $toolResults = $this->toolResultsContent($agent);
            $toolResult0 = $this->toolResultBlock($toolResults, 0);
            static::assertTrue($toolResult0['is_error'] ?? null);
            static::assertSame('RuntimeException: boom', $toolResult0['content'] ?? null);
        });
    }

    /** @throws Throwable */
    public function testResetClearsConversation(): void
    {
        CoroutineTestRunner::run(function (): void {
            $llm = new FakeStreamingLlmClient();
            $llm->queueScript($this->finalTurn('hi'));

            $agent = new ParallelStreamingAgent($llm, new FakeDispatcher(), [], '');
            iterator_to_array($agent->runStream('go'), false);

            static::assertNotSame([], $agent->messages);
            $agent->reset();
            static::assertSame([], $agent->messages);
        });
    }

    /** @param array<string, mixed> $block */
    private function toolUseId(array $block): mixed
    {
        return $block['tool_use_id'] ?? null;
    }

    /**
     * The tool_result message the agent fed back to the LLM (always index 2:
     * user prompt, assistant tool_use turn, then this).
     *
     * @return list<array<string, mixed>>
     */
    private function toolResultsContent(ParallelStreamingAgent $agent): array
    {
        $message = $agent->messages[2] ?? null;
        self::assertNotNull($message);

        return $message->content;
    }

    /**
     * @param list<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private function toolResultBlock(array $content, int $index): array
    {
        $block = $content[$index] ?? null;
        self::assertNotNull($block);

        return $block;
    }
}
