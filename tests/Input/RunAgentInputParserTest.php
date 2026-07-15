<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Support\JsonFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;

/**
 * @mago-expect lint:too-many-methods
 *
 * One method per behaviour scenario is intentional; merging via data
 * providers would obscure which contract is failing.
 */
#[CoversClass(RunAgentInputParser::class)]
#[CoversClass(Result::class)]
final class RunAgentInputParserTest extends TestCase
{
    /** @throws RuntimeException */
    public function testParsesMinimalValidBody(): void
    {
        $input = self::parseOk('Input/minimal.json');

        static::assertSame('t-1', $input->threadId);
        static::assertSame('r-1', $input->runId);
        static::assertSame('hi', $input->userMessage);
        static::assertSame([], $input->history);
        static::assertSame([], $input->declaredToolNames);
        static::assertSame([], $input->resume);
        static::assertNull($input->state);
    }

    /** @throws RuntimeException */
    public function testAcceptsOptionalFields(): void
    {
        $input = self::parseOk('Input/full.json');

        static::assertSame(['search'], $input->declaredToolNames);
        $context = $input->context[0] ?? null;
        static::assertInstanceOf(Context::class, $context);
        static::assertSame('d', $context->description);
        static::assertSame('v', $context->value);
        static::assertSame(['k' => 'v'], $input->state);
        static::assertSame(['a' => 1], $input->forwardedProps);
        static::assertCount(1, $input->resume);
        $resume = $input->resume[0] ?? null;
        static::assertInstanceOf(Resume::class, $resume);
        static::assertSame('i-1', $resume->interruptId);
        static::assertSame('resolved', $resume->status);
        static::assertNull($resume->payload);
    }

    /** @throws RuntimeException */
    public function testSplitsTriggerFromHistory(): void
    {
        $input = self::parseOk('Input/history-before-trigger.json');

        static::assertSame('second', $input->userMessage);
        static::assertSame(
            ['user', 'assistant'],
            array_map(static fn(Message $message): string => $message->role(), $input->history),
        );
    }

    public function testReturnsParseErrorForNonObjectBody(): void
    {
        $result = (new RunAgentInputParser())->parse('"not an object"');

        static::assertFalse($result->isOk());
        $error = $result->unwrapErr()[0] ?? null;
        static::assertInstanceOf(ParseError::class, $error);
        static::assertStringContainsString('must be a JSON object', $error->message);
    }

    public function testReturnsParseErrorForInvalidJson(): void
    {
        $result = (new RunAgentInputParser())->parse('{not json');

        static::assertFalse($result->isOk());
        $error = $result->unwrapErr()[0] ?? null;
        static::assertInstanceOf(ParseError::class, $error);
        static::assertStringStartsWith('Invalid JSON', $error->message);
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForMissingThreadId(): void
    {
        static::assertStringContainsString(
            "Missing or invalid 'threadId'",
            self::firstError('Input/missing-thread-id.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForEmptyRunId(): void
    {
        static::assertStringContainsString(
            "Missing or invalid 'runId'",
            self::firstError('Input/empty-run-id.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForMissingMessages(): void
    {
        static::assertStringContainsString(
            "Missing or invalid 'messages'",
            self::firstError('Input/missing-messages.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForUnknownMessageRole(): void
    {
        static::assertSame(
            "messages[1].role 'alien' is not a recognized AG-UI message role",
            self::firstError('Input/unknown-role.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForMessageMissingId(): void
    {
        static::assertSame('messages[0].id is required', self::firstError('Input/message-missing-id.json')->message);
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForToolMissingName(): void
    {
        static::assertSame('tools[1].name is required', self::firstError('Input/tools-missing-name.json')->message);
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForToolMessageMissingToolCallId(): void
    {
        static::assertSame(
            'messages[1].toolCallId is required',
            self::firstError('Input/tool-message-missing-tool-call-id.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorWhenTriggerUserMessageIsEmpty(): void
    {
        static::assertStringContainsString(
            'user message with text content',
            self::firstError('Input/empty-user-content.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorWhenNoUserMessagePresent(): void
    {
        static::assertStringContainsString(
            'user message with text content',
            self::firstError('Input/no-user-message.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForAssistantToolCallInvalidJsonArguments(): void
    {
        static::assertStringStartsWith(
            'messages[1].toolCalls[0].function.arguments is not valid JSON',
            self::firstError('Input/assistant-tool-call-invalid-arguments.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testReturnsParseErrorForAssistantToolCallNonObjectArguments(): void
    {
        static::assertSame(
            'messages[1].toolCalls[0].function.arguments must decode to a JSON object',
            self::firstError('Input/assistant-tool-call-non-object-arguments.json')->message,
        );
    }

    /** @throws RuntimeException */
    public function testProjectsUserMessageInputContentArrayToText(): void
    {
        $input = self::parseOk('Input/user-message-with-input-content.json');

        static::assertSame('describe this', $input->userMessage);
    }

    /** @throws RuntimeException */
    public function testAggregatesErrorsAcrossIndependentSiblings(): void
    {
        $messages = self::errorMessages('Input/multiple-errors.json');

        static::assertContains("Missing or invalid 'threadId'.", $messages);
        static::assertContains("messages[1].role '' is not a recognized AG-UI message role", $messages);
        static::assertContains('tools[0].name is required', $messages);
        static::assertCount(3, $messages);
    }

    /** @throws RuntimeException */
    public function testAggregatesErrorsAcrossListEntries(): void
    {
        $messages = self::errorMessages('Input/multiple-message-errors.json');

        static::assertContains("messages[1].role '' is not a recognized AG-UI message role", $messages);
        static::assertContains('messages[2].toolCallId is required', $messages);
        static::assertCount(2, $messages);
    }

    /** @throws RuntimeException */
    public function testAggregatesMultipleErrorsWithinOneEntry(): void
    {
        $messages = self::errorMessages('Input/activity-missing-type-and-content.json');

        static::assertContains('messages[1].activityType is required', $messages);
        static::assertContains('messages[1].content is required', $messages);
        static::assertCount(2, $messages);
    }

    /** @throws RuntimeException */
    public function testAggregatesToolFieldErrors(): void
    {
        $messages = self::errorMessages('Input/tool-missing-name-and-parameters.json');

        static::assertContains('tools[0].name is required', $messages);
        static::assertContains('tools[0].parameters is required', $messages);
        static::assertCount(2, $messages);
    }

    /** @throws RuntimeException */
    public function testAggregatesContextFieldErrors(): void
    {
        $messages = self::errorMessages('Input/context-missing-both.json');

        static::assertContains('context[0].description is required', $messages);
        static::assertContains('context[0].value is required', $messages);
        static::assertCount(2, $messages);
    }

    /** @throws RuntimeException */
    public function testAggregatesResumeFieldErrors(): void
    {
        $messages = self::errorMessages('Input/resume-missing-both.json');

        static::assertContains('resume[0].interruptId is required', $messages);
        static::assertContains('resume[0].status is required', $messages);
        static::assertCount(2, $messages);
    }

    /** @throws RuntimeException */
    public function testAggregatesToolMessageFieldErrors(): void
    {
        $messages = self::errorMessages('Input/tool-message-missing-both.json');

        static::assertContains('messages[1].toolCallId is required', $messages);
        static::assertContains('messages[1].content is required', $messages);
        static::assertCount(2, $messages);
    }

    /** @throws RuntimeException */
    public function testAggregatesAssistantToolCallFieldErrors(): void
    {
        $messages = self::errorMessages('Input/assistant-tool-call-missing-id-and-name.json');

        static::assertContains('messages[1].toolCalls[0].id is required', $messages);
        static::assertContains('messages[1].toolCalls[0].function.name is required', $messages);
        static::assertCount(2, $messages);
    }

    /** @throws RuntimeException */
    private static function parseOk(string $fixture): RunAgentInput
    {
        $result = (new RunAgentInputParser())->parse(JsonFixture::load($fixture));
        static::assertTrue($result->isOk());

        return $result->unwrap();
    }

    /**
     * @return list<ParseError>
     *
     * @throws RuntimeException
     */
    private static function errors(string $fixture): array
    {
        $result = (new RunAgentInputParser())->parse(JsonFixture::load($fixture));
        static::assertFalse($result->isOk());

        $errors = $result->unwrapErr();
        static::assertNotEmpty($errors);

        return $errors;
    }

    /** @throws RuntimeException */
    private static function firstError(string $fixture): ParseError
    {
        $error = self::errors($fixture)[0] ?? null;
        static::assertInstanceOf(ParseError::class, $error);

        return $error;
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException
     */
    private static function errorMessages(string $fixture): array
    {
        return array_map(static fn(ParseError $error): string => $error->message, self::errors($fixture));
    }
}
