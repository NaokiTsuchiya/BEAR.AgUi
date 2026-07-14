<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Shared;

use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\Message;
use Example\Shared\OpenAiMessageMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the bear Message -> OpenAI messages mapping (T3-b, D20).
 * No CoversClass: example/ classes are outside the coverage include path.
 */
final class OpenAiMessageMapperTest extends TestCase
{
    public function testMapsUserTextMessage(): void
    {
        $mapped = (new OpenAiMessageMapper())->map('', [Message::user('hello')]);

        static::assertSame([['role' => 'user', 'content' => 'hello']], $mapped);
    }

    public function testPrependsSystemMessageWhenNonEmpty(): void
    {
        $mapped = (new OpenAiMessageMapper())->map('You are helpful.', [Message::user('hi')]);

        static::assertSame(
            [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'hi'],
            ],
            $mapped,
        );
    }

    public function testMapsAssistantTextAndToolUseToToolCalls(): void
    {
        $assistant = Message::assistant([
            ['type' => 'text', 'text' => 'Let me check.'],
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'get_time', 'input' => ['timezone' => 'UTC']],
        ]);

        $mapped = (new OpenAiMessageMapper())->map('', [$assistant]);

        static::assertSame(
            [
                [
                    'role' => 'assistant',
                    'content' => 'Let me check.',
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_time', 'arguments' => '{"timezone":"UTC"}'],
                        ],
                    ],
                ],
            ],
            $mapped,
        );
    }

    public function testAssistantWithOnlyToolUseHasNullContent(): void
    {
        $assistant = Message::assistant([
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'get_time', 'input' => []],
        ]);

        $mapped = (new OpenAiMessageMapper())->map('', [$assistant]);

        static::assertNull($mapped[0]['content']);

        $toolCalls = $mapped[0]['tool_calls'];
        static::assertIsArray($toolCalls);
        $toolCall = $toolCalls[0];
        static::assertIsArray($toolCall);
        $function = $toolCall['function'];
        static::assertIsArray($function);
        static::assertSame('{}', $function['arguments']);
    }

    public function testExpandsToolResultsToOneToolMessageEach(): void
    {
        $results = Message::toolResults([
            ToolResult::success('call_1', 'ok'),
            ToolResult::success('call_2', ['count' => 2]),
        ]);

        $mapped = (new OpenAiMessageMapper())->map('', [$results]);

        static::assertSame(
            [
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'ok'],
                ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"count":2}'],
            ],
            $mapped,
        );
    }
}
