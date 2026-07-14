<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\CliClient;

use Example\CliClient\ConversationLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversationLog::class)]
final class ConversationLogTest extends TestCase
{
    public function testTextOnlyRoundTrip(): void
    {
        $log = new ConversationLog();
        $log->appendUser('Hello');

        $log->observe(['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm-1', 'role' => 'assistant']);
        $log->observe(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm-1', 'delta' => 'Hi ']);
        $log->observe(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm-1', 'delta' => 'there']);
        $log->observe(['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm-1']);
        $log->observe(['type' => 'RUN_FINISHED', 'threadId' => 't-1', 'runId' => 'r-1']);

        static::assertSame(
            [
                ['id' => $log->toMessages()[0]['id'], 'role' => 'user', 'content' => 'Hello'],
                ['id' => 'm-1', 'role' => 'assistant', 'content' => 'Hi there'],
            ],
            $log->toMessages(),
        );
    }

    public function testSingleToolCallRoundTrip(): void
    {
        $log = new ConversationLog();
        $log->appendUser('What is the weather?');

        $log->observe(['type' => 'TOOL_CALL_START', 'toolCallId' => 'tc-1', 'toolCallName' => 'weather_get']);
        $log->observe(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'tc-1', 'delta' => '{"city":']);
        $log->observe(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'tc-1', 'delta' => '"Tokyo"}']);
        $log->observe(['type' => 'TOOL_CALL_END', 'toolCallId' => 'tc-1']);
        $log->observe([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'm-2',
            'toolCallId' => 'tc-1',
            'content' => 'Sunny',
        ]);
        $log->observe(['type' => 'RUN_FINISHED', 'threadId' => 't-1', 'runId' => 'r-1']);

        $messages = $log->toMessages();

        static::assertSame('user', $messages[0]['role']);
        static::assertSame(
            [
                'id' => $messages[1]['id'],
                'role' => 'assistant',
                'content' => '',
                'toolCalls' => [
                    [
                        'id' => 'tc-1',
                        'type' => 'function',
                        'function' => ['name' => 'weather_get', 'arguments' => '{"city":"Tokyo"}'],
                    ],
                ],
            ],
            $messages[1],
        );
        static::assertSame(
            ['id' => 'm-2', 'role' => 'tool', 'content' => 'Sunny', 'toolCallId' => 'tc-1'],
            $messages[2],
        );
    }

    public function testInterleavedParallelToolCalls(): void
    {
        $log = new ConversationLog();
        $log->appendUser('Weather in Tokyo and the news, please.');

        $log->observe(['type' => 'TOOL_CALL_START', 'toolCallId' => 'tc-weather', 'toolCallName' => 'weather_get']);
        $log->observe(['type' => 'TOOL_CALL_START', 'toolCallId' => 'tc-news', 'toolCallName' => 'news_get']);
        $log->observe(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'tc-weather', 'delta' => '{}']);
        $log->observe(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'tc-news', 'delta' => '{}']);
        $log->observe(['type' => 'TOOL_CALL_END', 'toolCallId' => 'tc-weather']);
        $log->observe(['type' => 'TOOL_CALL_END', 'toolCallId' => 'tc-news']);
        $log->observe([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'm-w',
            'toolCallId' => 'tc-weather',
            'content' => 'Sunny',
        ]);
        $log->observe([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'm-n',
            'toolCallId' => 'tc-news',
            'content' => 'Nothing new',
        ]);
        $log->observe(['type' => 'RUN_FINISHED', 'threadId' => 't-1', 'runId' => 'r-1']);

        $messages = $log->toMessages();

        static::assertSame(
            [
                [
                    'id' => 'tc-weather',
                    'type' => 'function',
                    'function' => ['name' => 'weather_get', 'arguments' => '{}'],
                ],
                ['id' => 'tc-news', 'type' => 'function', 'function' => ['name' => 'news_get', 'arguments' => '{}']],
            ],
            $messages[1]['toolCalls'],
        );
        static::assertSame('tool', $messages[2]['role']);
        static::assertSame('tc-weather', $messages[2]['toolCallId']);
        static::assertSame('tool', $messages[3]['role']);
        static::assertSame('tc-news', $messages[3]['toolCallId']);
    }

    public function testSecondTurnCarriesFirstTurnHistory(): void
    {
        $log = new ConversationLog();

        $log->appendUser('Hello');
        $log->observe(['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm-1']);
        $log->observe(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm-1', 'delta' => 'Hi']);
        $log->observe(['type' => 'RUN_FINISHED']);

        $firstTurnMessages = $log->toMessages();
        static::assertCount(2, $firstTurnMessages);

        $log->appendUser('How are you?');
        $log->observe(['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm-2']);
        $log->observe(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm-2', 'delta' => 'Great']);
        $log->observe(['type' => 'RUN_FINISHED']);

        $secondTurnMessages = $log->toMessages();

        static::assertSame($firstTurnMessages, [$secondTurnMessages[0], $secondTurnMessages[1]]);
        static::assertSame('user', $secondTurnMessages[2]['role']);
        static::assertSame('How are you?', $secondTurnMessages[2]['content']);
        static::assertSame(['id' => 'm-2', 'role' => 'assistant', 'content' => 'Great'], $secondTurnMessages[3]);
    }

    public function testToolCallWithNoArgsDeltaDefaultsToEmptyJsonObject(): void
    {
        $log = new ConversationLog();
        $log->appendUser('What time is it?');

        $log->observe(['type' => 'TOOL_CALL_START', 'toolCallId' => 'tc-1', 'toolCallName' => 'get_time']);
        $log->observe(['type' => 'TOOL_CALL_END', 'toolCallId' => 'tc-1']);
        $log->observe([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'm-2',
            'toolCallId' => 'tc-1',
            'content' => 'not enabled',
        ]);

        $messages = $log->toMessages();

        static::assertSame('{}', $messages[1]['toolCalls'][0]['function']['arguments']);
    }

    public function testInterruptedRunClosesDanglingToolCallArguments(): void
    {
        $log = new ConversationLog();
        $log->appendUser('Remind me to buy milk.');

        // A confirmation interrupt ends the run right after TOOL_CALL_START:
        // no TOOL_CALL_ARGS, no TOOL_CALL_RESULT.
        $log->observe(['type' => 'TOOL_CALL_START', 'toolCallId' => 'tc-1', 'toolCallName' => 'reminder_set']);
        $log->observe(['type' => 'RUN_FINISHED', 'threadId' => 't-1', 'runId' => 'r-1', 'outcome' => 'interrupt']);

        $messages = $log->toMessages();

        static::assertSame('{}', $messages[1]['toolCalls'][0]['function']['arguments']);
    }

    public function testEventsWithUnknownTypeAreIgnored(): void
    {
        $log = new ConversationLog();
        $log->appendUser('Hello');

        $log->observe(['type' => 'RUN_STARTED', 'threadId' => 't-1', 'runId' => 'r-1']);

        static::assertCount(1, $log->toMessages());
    }
}
