<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use NaokiTsuchiya\BEARAgUi\Input\Message\ActivityMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantToolCall;
use NaokiTsuchiya\BEARAgUi\Input\Message\ReasoningMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\SystemMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolOutcome;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageHistoryMapper::class)]
final class MessageHistoryMapperTest extends TestCase
{
    public function testTextOnlyTurnsAreMappedAsUserAndAssistant(): void
    {
        $history = (new MessageHistoryMapper())->map([
            new UserMessage('m1', 'hi'),
            new AssistantMessage('m2', 'hello', []),
        ]);

        static::assertCount(2, $history);
        static::assertSame('user', $history[0]->role);
        static::assertSame([['type' => 'text', 'text' => 'hi']], $history[0]->content);
        static::assertSame('assistant', $history[1]->role);
        static::assertSame([['type' => 'text', 'text' => 'hello']], $history[1]->content);
    }

    public function testAssistantToolCallProducesToolUseBlock(): void
    {
        $history = (new MessageHistoryMapper())->map([
            new AssistantMessage('m1', 'looking that up', [
                new AssistantToolCall('call-1', 'search', ['q' => 'phpunit']),
            ]),
            new ToolMessage('m2', 'call-1', ToolOutcome::success('matched 3 results')),
        ]);

        static::assertCount(2, $history);
        static::assertSame(
            [
                ['type' => 'text', 'text' => 'looking that up'],
                ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'search', 'input' => ['q' => 'phpunit']],
            ],
            $history[0]->content,
        );

        $resultMessage = $history[1];
        static::assertSame('user', $resultMessage->role);
        static::assertSame(
            [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => 'call-1',
                    'content' => 'matched 3 results',
                    'is_error' => false,
                ],
            ],
            $resultMessage->content,
        );
    }

    public function testConsecutiveToolMessagesAreGroupedAsOneToolResults(): void
    {
        $history = (new MessageHistoryMapper())->map([
            new AssistantMessage('m1', null, [
                new AssistantToolCall('call-a', 'search', []),
                new AssistantToolCall('call-b', 'lookup', []),
            ]),
            new ToolMessage('m2', 'call-a', ToolOutcome::success('a-out')),
            new ToolMessage('m3', 'call-b', ToolOutcome::success('b-out')),
        ]);

        static::assertCount(2, $history);
        static::assertCount(2, $history[1]->content);
        static::assertSame('call-a', $history[1]->content[0]['tool_use_id']);
        static::assertSame('call-b', $history[1]->content[1]['tool_use_id']);
    }

    public function testToolFailureMapsToErrorResult(): void
    {
        $history = (new MessageHistoryMapper())->map([
            new AssistantMessage('m1', null, [new AssistantToolCall('call-1', 'do', [])]),
            new ToolMessage('m2', 'call-1', ToolOutcome::failure(null, 'boom')),
        ]);

        $result = $history[1]->content[0];
        static::assertTrue($result['is_error']);
        static::assertSame('boom', $result['content']);
    }

    public function testSystemActivityAndReasoningAreSkipped(): void
    {
        $history = (new MessageHistoryMapper())->map([
            new SystemMessage('s', 'system prompt'),
            new ActivityMessage('a', 'profile', ['n' => 1]),
            new ReasoningMessage('r', 'thinking', null),
            new UserMessage('u', 'hi'),
        ]);

        static::assertCount(1, $history);
        static::assertSame('user', $history[0]->role);
    }

    public function testEmptyInputReturnsEmptyHistory(): void
    {
        static::assertSame([], (new MessageHistoryMapper())->map([]));
    }
}
