<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Runtime;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\Support\CoroutineTestRunner;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

#[CoversClass(ParallelStreamingAgentFactory::class)]
final class ParallelStreamingAgentFactoryTest extends TestCase
{
    public function testNewInstanceReturnsParallelAgentWithHistorySeeded(): void
    {
        $factory = self::makeFactory();

        $history = [Message::user('first'), Message::assistant([['type' => 'text', 'text' => 'reply']])];
        $agent = $factory->newInstance(new ToolCallRegistry(), $history);

        static::assertInstanceOf(ParallelStreamingAgent::class, $agent);
        static::assertSame($history, $agent->messages);
    }

    public function testKnownToolNamesReturnsRegisteredToolNames(): void
    {
        $factory = self::makeFactory([
            new Tool('search', 'd', []),
            new Tool('fetch', 'd', []),
        ]);

        static::assertSame(['search', 'fetch'], $factory->knownToolNames());
    }

    #[RequiresPhpExtension('swoole')]
    public function testWiresRecordingDecoratorsAroundClientAndDispatcher(): void
    {
        CoroutineTestRunner::run(static function (): void {
            $llm = new FakeStreamingLlmClient();
            $llm->queueScript([
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'search']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"q":"x"}']),
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

            $factory = new ParallelStreamingAgentFactory(
                client: $llm,
                dispatcher: $dispatcher,
                tools: [new Tool('search', 'd', ['type' => 'object', 'properties' => [], 'required' => []])],
                systemPrompt: 'system',
            );

            // The same registry instance is handed in as recorder and read
            // back as view — the decorators must fill it during the run.
            $registry = new ToolCallRegistry();
            $agent = $factory->newInstance($registry, []);
            iterator_to_array($agent->runStream('go'), false);

            $started = $registry->takeStarted('search');
            static::assertNotNull($started);
            static::assertSame('call-1', $started->id);

            $outcome = $registry->resultFor('call-1');
            static::assertNotNull($outcome);
            static::assertSame('{"q":"x"}', $outcome->input);
            static::assertSame('hits', $outcome->content);
            static::assertFalse($outcome->isError);
        });
    }

    /** @param list<Tool> $tools */
    private static function makeFactory(array $tools = []): ParallelStreamingAgentFactory
    {
        return new ParallelStreamingAgentFactory(
            client: new FakeStreamingLlmClient(),
            dispatcher: new FakeDispatcher(),
            tools: $tools,
            systemPrompt: 'system',
        );
    }
}
