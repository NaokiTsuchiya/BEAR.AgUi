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

use function array_map;
use function iterator_to_array;

#[CoversClass(ParallelStreamingAgent::class)]
#[RequiresPhpExtension('swoole')]
final class ParallelStreamingAgentTest extends TestCase
{
    use ParallelAgentScenarioFixture;

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
            // completion order under the scheduler is arbitrary.
            static::assertSame(['alpha', 'beta', 'gamma'], [
                $events[4]->data['toolName'],
                $events[5]->data['toolName'],
                $events[6]->data['toolName'],
            ]);

            // Tool results are fed back to the LLM in pending order.
            $toolResults = $agent->messages[2]->content;
            static::assertSame(
                ['call-a', 'call-b', 'call-c'],
                array_map(static fn(array $block): mixed => $block['tool_use_id'], $toolResults),
            );
        });
    }

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

            $toolResults = $agent->messages[2]->content;
            static::assertFalse($toolResults[0]['is_error']);
            static::assertTrue($toolResults[1]['is_error']);
            static::assertSame('User cancelled this operation.', $toolResults[1]['content']);
        });
    }

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

            $toolResults = $agent->messages[2]->content;
            static::assertSame(
                ['call-a', 'call-d'],
                array_map(static fn(array $block): mixed => $block['tool_use_id'], $toolResults),
            );
        });
    }

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

            $toolResults = $agent->messages[2]->content;
            static::assertTrue($toolResults[0]['is_error']);
            static::assertSame('Tool is not enabled: ghost', $toolResults[0]['content']);
            static::assertFalse($toolResults[1]['is_error']);
        });
    }

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

            $toolResults = $agent->messages[2]->content;
            static::assertTrue($toolResults[0]['is_error']);
            static::assertSame('RuntimeException: boom', $toolResults[0]['content']);
        });
    }

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
}
