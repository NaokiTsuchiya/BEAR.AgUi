<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Runtime\StreamingAgent;
use BEAR\ToolUse\Schema\Tool;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamingAgentFactory::class)]
final class StreamingAgentFactoryTest extends TestCase
{
    public function testCreateReturnsStreamingAgentWithHistorySeeded(): void
    {
        $factory = self::makeFactory();

        $history = [Message::user('first'), Message::assistant([['type' => 'text', 'text' => 'reply']])];
        $agent = $factory->newInstance(new ToolCallRegistry(), $history);

        static::assertInstanceOf(StreamingAgent::class, $agent);
        static::assertSame($history, $agent->messages);
    }

    public function testCreateAcceptsEmptyHistory(): void
    {
        $factory = self::makeFactory();

        $agent = $factory->newInstance(new ToolCallRegistry(), []);

        static::assertInstanceOf(StreamingAgent::class, $agent);
        static::assertSame([], $agent->messages);
    }

    public function testKnownToolNamesReturnsRegisteredToolNames(): void
    {
        $factory = self::makeFactory([
            new Tool('search', 'd', []),
            new Tool('fetch', 'd', []),
        ]);

        static::assertSame(['search', 'fetch'], $factory->knownToolNames());
    }

    public function testKnownToolNamesReturnsEmptyListWhenNoTools(): void
    {
        $factory = self::makeFactory();

        static::assertSame([], $factory->knownToolNames());
    }

    /** @param list<Tool> $tools */
    private static function makeFactory(array $tools = []): StreamingAgentFactory
    {
        return new StreamingAgentFactory(
            client: new FakeStreamingLlmClient(),
            dispatcher: new FakeDispatcher(),
            tools: $tools,
            systemPrompt: 'system',
        );
    }
}
