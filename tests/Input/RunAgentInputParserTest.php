<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use NaokiTsuchiya\BEARAgUi\Support\JsonFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * @mago-expect lint:too-many-methods
 *
 * One method per behaviour scenario is intentional; merging via data
 * providers would obscure which contract is failing.
 */
#[CoversClass(RunAgentInputParser::class)]
final class RunAgentInputParserTest extends TestCase
{
    public function testParsesMinimalValidBody(): void
    {
        $input = self::parse('Input/minimal.json');

        static::assertInstanceOf(RunAgentInput::class, $input);
        static::assertSame('t-1', $input->threadId);
        static::assertSame('r-1', $input->runId);
        static::assertSame('hi', $input->userMessage);
        static::assertSame([], $input->history);
        static::assertSame([], $input->declaredToolNames);
        static::assertSame([], $input->resume);
        static::assertNull($input->state);
    }

    public function testAcceptsOptionalFields(): void
    {
        $input = self::parse('Input/full.json');

        static::assertInstanceOf(RunAgentInput::class, $input);
        static::assertSame(['search'], $input->declaredToolNames);
        static::assertSame('d', $input->context[0]->description);
        static::assertSame('v', $input->context[0]->value);
        static::assertSame(['k' => 'v'], $input->state);
        static::assertSame(['a' => 1], $input->forwardedProps);
        static::assertCount(1, $input->resume);
        static::assertSame('i-1', $input->resume[0]->interruptId);
        static::assertSame('resolved', $input->resume[0]->status);
        static::assertNull($input->resume[0]->payload);
    }

    public function testSplitsTriggerFromHistory(): void
    {
        $input = self::parse('Input/history-before-trigger.json');

        static::assertInstanceOf(RunAgentInput::class, $input);
        static::assertSame('second', $input->userMessage);
        static::assertSame(
            ['user', 'assistant'],
            array_map(static fn($message): string => $message->role(), $input->history),
        );
    }

    public function testReturnsParseErrorForNonObjectBody(): void
    {
        $errors = (new RunAgentInputParser())->parse('"not an object"');

        static::assertIsArray($errors);
        static::assertStringContainsString('must be a JSON object', $errors[0]->message);
    }

    public function testReturnsParseErrorForInvalidJson(): void
    {
        $errors = (new RunAgentInputParser())->parse('{not json');

        static::assertIsArray($errors);
        static::assertStringStartsWith('Invalid JSON', $errors[0]->message);
    }

    public function testReturnsParseErrorForMissingThreadId(): void
    {
        static::assertStringContainsString(
            "Missing or invalid 'threadId'",
            self::firstError('Input/missing-thread-id.json')->message,
        );
    }

    public function testReturnsParseErrorForEmptyRunId(): void
    {
        static::assertStringContainsString(
            "Missing or invalid 'runId'",
            self::firstError('Input/empty-run-id.json')->message,
        );
    }

    public function testReturnsParseErrorForMissingMessages(): void
    {
        static::assertStringContainsString(
            "Missing or invalid 'messages'",
            self::firstError('Input/missing-messages.json')->message,
        );
    }

    public function testReturnsParseErrorForUnknownMessageRole(): void
    {
        static::assertSame(
            "messages[1].role 'alien' is not a recognized AG-UI message role",
            self::firstError('Input/unknown-role.json')->message,
        );
    }

    public function testReturnsParseErrorForMessageMissingId(): void
    {
        static::assertSame('messages[0].id is required', self::firstError('Input/message-missing-id.json')->message);
    }

    public function testReturnsParseErrorForToolMissingName(): void
    {
        static::assertSame('tools[1].name is required', self::firstError('Input/tools-missing-name.json')->message);
    }

    public function testReturnsParseErrorForToolMessageMissingToolCallId(): void
    {
        static::assertSame(
            'messages[1].toolCallId is required',
            self::firstError('Input/tool-message-missing-tool-call-id.json')->message,
        );
    }

    public function testReturnsParseErrorWhenTriggerUserMessageIsEmpty(): void
    {
        static::assertStringContainsString(
            'user message with text content',
            self::firstError('Input/empty-user-content.json')->message,
        );
    }

    public function testReturnsParseErrorWhenNoUserMessagePresent(): void
    {
        static::assertStringContainsString(
            'user message with text content',
            self::firstError('Input/no-user-message.json')->message,
        );
    }

    public function testReturnsParseErrorForAssistantToolCallInvalidJsonArguments(): void
    {
        static::assertStringStartsWith(
            'messages[1].toolCalls[0].function.arguments is not valid JSON',
            self::firstError('Input/assistant-tool-call-invalid-arguments.json')->message,
        );
    }

    public function testReturnsParseErrorForAssistantToolCallNonObjectArguments(): void
    {
        static::assertSame(
            'messages[1].toolCalls[0].function.arguments must decode to a JSON object',
            self::firstError('Input/assistant-tool-call-non-object-arguments.json')->message,
        );
    }

    public function testProjectsUserMessageInputContentArrayToText(): void
    {
        $input = self::parse('Input/user-message-with-input-content.json');

        static::assertInstanceOf(RunAgentInput::class, $input);
        static::assertSame('describe this', $input->userMessage);
    }

    public function testAggregatesErrorsAcrossIndependentSiblings(): void
    {
        $messages = self::errorMessages('Input/multiple-errors.json');

        static::assertContains("Missing or invalid 'threadId'.", $messages);
        static::assertContains("messages[1].role '' is not a recognized AG-UI message role", $messages);
        static::assertContains('tools[0].name is required', $messages);
        static::assertCount(3, $messages);
    }

    public function testAggregatesErrorsAcrossListEntries(): void
    {
        $messages = self::errorMessages('Input/multiple-message-errors.json');

        static::assertContains("messages[1].role '' is not a recognized AG-UI message role", $messages);
        static::assertContains('messages[2].toolCallId is required', $messages);
        static::assertCount(2, $messages);
    }

    private static function parse(string $fixture): RunAgentInput|array
    {
        return (new RunAgentInputParser())->parse(JsonFixture::load($fixture));
    }

    /** @return list<ParseError> */
    private static function errors(string $fixture): array
    {
        $result = self::parse($fixture);
        static::assertIsArray($result);
        static::assertNotEmpty($result);

        return $result;
    }

    private static function firstError(string $fixture): ParseError
    {
        return self::errors($fixture)[0];
    }

    /** @return list<string> */
    private static function errorMessages(string $fixture): array
    {
        return array_map(static fn(ParseError $error): string => $error->message, self::errors($fixture));
    }
}
