<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\CliClient;

use Example\CliClient\ConversationLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function is_array;

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

        $messages = $log->toMessages();
        $firstMessage = self::messageAt($messages, 0);

        static::assertSame(
            [
                ['id' => self::field($firstMessage, 'id'), 'role' => 'user', 'content' => 'Hello'],
                ['id' => 'm-1', 'role' => 'assistant', 'content' => 'Hi there'],
            ],
            $messages,
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
        $firstMessage = self::messageAt($messages, 0);
        $secondMessage = self::messageAt($messages, 1);
        $thirdMessage = self::messageAt($messages, 2);

        static::assertSame('user', self::field($firstMessage, 'role'));
        static::assertSame(
            [
                'id' => self::field($secondMessage, 'id'),
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
            $secondMessage,
        );
        static::assertSame(
            ['id' => 'm-2', 'role' => 'tool', 'content' => 'Sunny', 'toolCallId' => 'tc-1'],
            $thirdMessage,
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
        $secondMessage = self::messageAt($messages, 1);
        $thirdMessage = self::messageAt($messages, 2);
        $fourthMessage = self::messageAt($messages, 3);

        static::assertSame(
            [
                [
                    'id' => 'tc-weather',
                    'type' => 'function',
                    'function' => ['name' => 'weather_get', 'arguments' => '{}'],
                ],
                ['id' => 'tc-news', 'type' => 'function', 'function' => ['name' => 'news_get', 'arguments' => '{}']],
            ],
            self::field($secondMessage, 'toolCalls'),
        );
        static::assertSame('tool', self::field($thirdMessage, 'role'));
        static::assertSame('tc-weather', self::field($thirdMessage, 'toolCallId'));
        static::assertSame('tool', self::field($fourthMessage, 'role'));
        static::assertSame('tc-news', self::field($fourthMessage, 'toolCallId'));
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
        $secondTurnFirstMessage = self::messageAt($secondTurnMessages, 0);
        $secondTurnSecondMessage = self::messageAt($secondTurnMessages, 1);
        $secondTurnThirdMessage = self::messageAt($secondTurnMessages, 2);
        $secondTurnFourthMessage = self::messageAt($secondTurnMessages, 3);

        static::assertSame($firstTurnMessages, [$secondTurnFirstMessage, $secondTurnSecondMessage]);
        static::assertSame('user', self::field($secondTurnThirdMessage, 'role'));
        static::assertSame('How are you?', self::field($secondTurnThirdMessage, 'content'));
        static::assertSame(['id' => 'm-2', 'role' => 'assistant', 'content' => 'Great'], $secondTurnFourthMessage);
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
        $secondMessage = self::messageAt($messages, 1);

        $toolCalls = self::asArray(self::field($secondMessage, 'toolCalls'));
        static::assertIsArray($toolCalls);
        $toolCall = self::asArray($toolCalls[0] ?? null);
        static::assertIsArray($toolCall);
        $function = self::asArray(self::field($toolCall, 'function'));
        static::assertIsArray($function);
        static::assertSame('{}', self::field($function, 'arguments'));
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
        $secondMessage = self::messageAt($messages, 1);

        $toolCalls = self::asArray(self::field($secondMessage, 'toolCalls'));
        static::assertIsArray($toolCalls);
        $toolCall = self::asArray($toolCalls[0] ?? null);
        static::assertIsArray($toolCall);
        $function = self::asArray(self::field($toolCall, 'function'));
        static::assertIsArray($function);
        static::assertSame('{}', self::field($function, 'arguments'));
    }

    public function testEventsWithUnknownTypeAreIgnored(): void
    {
        $log = new ConversationLog();
        $log->appendUser('Hello');

        $log->observe(['type' => 'RUN_STARTED', 'threadId' => 't-1', 'runId' => 'r-1']);

        static::assertCount(1, $log->toMessages());
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    private static function messageAt(array $messages, int $index): array
    {
        return $messages[$index] ?? [];
    }

    /** @param array<array-key, mixed> $message */
    private static function field(array $message, int|string $key): mixed
    {
        return $message[$key] ?? null;
    }

    /** @return array<array-key, mixed>|null */
    private static function asArray(mixed $value): array|null
    {
        return is_array($value) ? $value : null;
    }
}
