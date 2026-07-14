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
        $userMessage = $history[0];
        $assistantMessage = $history[1];
        static::assertNotNull($userMessage);
        static::assertNotNull($assistantMessage);
        static::assertSame('user', $userMessage->role);
        static::assertSame([['type' => 'text', 'text' => 'hi']], $userMessage->content);
        static::assertSame('assistant', $assistantMessage->role);
        static::assertSame([['type' => 'text', 'text' => 'hello']], $assistantMessage->content);
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
        $assistantMessage = $history[0];
        static::assertNotNull($assistantMessage);
        static::assertSame(
            [
                ['type' => 'text', 'text' => 'looking that up'],
                ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'search', 'input' => ['q' => 'phpunit']],
            ],
            $assistantMessage->content,
        );

        $resultMessage = $history[1];
        static::assertNotNull($resultMessage);
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
        $toolResultsMessage = $history[1];
        static::assertNotNull($toolResultsMessage);
        static::assertCount(2, $toolResultsMessage->content);
        static::assertSame('call-a', $toolResultsMessage->content[0]['tool_use_id']);
        static::assertSame('call-b', $toolResultsMessage->content[1]['tool_use_id']);
    }

    public function testToolFailureMapsToErrorResult(): void
    {
        $history = (new MessageHistoryMapper())->map([
            new AssistantMessage('m1', null, [new AssistantToolCall('call-1', 'do', [])]),
            new ToolMessage('m2', 'call-1', ToolOutcome::failure(null, 'boom')),
        ]);

        $toolResultsMessage = $history[1];
        static::assertNotNull($toolResultsMessage);
        $result = $toolResultsMessage->content[0];
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
        $userMessage = $history[0];
        static::assertNotNull($userMessage);
        static::assertSame('user', $userMessage->role);
    }

    public function testEmptyInputReturnsEmptyHistory(): void
    {
        static::assertSame([], (new MessageHistoryMapper())->map([]));
    }
}
