<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolOutcome;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * @mago-expect lint:too-many-methods
 *
 * One method per behaviour scenario is intentional; merging via data
 * providers would obscure which contract is failing.
 */
#[CoversClass(RunAgentInput::class)]
final class RunAgentInputTest extends TestCase
{
    public function testLastUserMessageReturnsMostRecent(): void
    {
        $input = self::makeInput([
            new UserMessage('m1', 'first'),
            new AssistantMessage('m2', 'reply', []),
            new UserMessage('m3', 'second'),
        ]);

        static::assertSame('second', $input->lastUserMessage());
    }

    public function testLastUserMessageSkipsNonUserMessages(): void
    {
        $input = self::makeInput([
            new UserMessage('m1', 'hi'),
            new ToolMessage('m2', 'call-1', ToolOutcome::success('result')),
        ]);

        static::assertSame('hi', $input->lastUserMessage());
    }

    public function testLastUserMessageReturnsParseErrorWhenAbsent(): void
    {
        $input = self::makeInput([new AssistantMessage('m1', 'reply', [])]);

        $result = $input->lastUserMessage();

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringContainsString('No user message', $result->message);
    }

    public function testLastUserMessageReturnsParseErrorWhenTextIsEmpty(): void
    {
        $input = self::makeInput([new UserMessage('m1', '')]);

        $result = $input->lastUserMessage();

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringContainsString('no text content', $result->message);
    }

    public function testHistoryMessagesDropsLastUser(): void
    {
        $input = self::makeInput([
            new UserMessage('m1', 'first'),
            new AssistantMessage('m2', 'reply', []),
            new UserMessage('m3', 'second'),
        ]);

        static::assertSame(['user', 'assistant'], self::rolesOf($input->historyMessages()));
    }

    public function testHistoryMessagesKeepsTrailingNonUser(): void
    {
        $input = self::makeInput([
            new UserMessage('m1', 'hi'),
            new AssistantMessage('m2', 'reply', []),
            new UserMessage('m3', 'follow-up'),
            new ToolMessage('m4', 'call-1', ToolOutcome::success('result')),
        ]);

        static::assertSame(['user', 'assistant'], self::rolesOf($input->historyMessages()));
    }

    public function testHistoryMessagesReturnsAllWhenNoUser(): void
    {
        $input = self::makeInput([new AssistantMessage('m1', 'reply', [])]);

        static::assertSame(['assistant'], self::rolesOf($input->historyMessages()));
    }

    public function testDeclaredToolNamesProjectsToolNames(): void
    {
        $input = self::makeInput(messages: [], tools: [
            new Tool('search', '', []),
            new Tool('fetch', '', []),
        ]);

        static::assertSame(['search', 'fetch'], $input->declaredToolNames());
    }

    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     */
    private static function makeInput(array $messages, array $tools = []): RunAgentInput
    {
        return new RunAgentInput(
            threadId: 't',
            runId: 'r',
            messages: $messages,
            tools: $tools,
            context: [],
            state: null,
            forwardedProps: [],
            resume: [],
        );
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<string>
     */
    private static function rolesOf(array $messages): array
    {
        return array_map(static fn(Message $m): string => $m->role(), $messages);
    }
}
