<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use NaokiTsuchiya\BEARAgUi\Support\JsonFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        static::assertCount(1, $input->messages);
        $first = $input->messages[0];
        static::assertInstanceOf(UserMessage::class, $first);
        static::assertSame('hi', $first->text);
        static::assertSame([], $input->tools);
        static::assertSame([], $input->resume);
        static::assertNull($input->state);
    }

    public function testAcceptsOptionalFields(): void
    {
        $input = self::parse('Input/full.json');

        static::assertInstanceOf(RunAgentInput::class, $input);
        static::assertSame(['search'], $input->declaredToolNames());
        static::assertSame('d', $input->tools[0]->description);
        static::assertSame(['q' => 'string'], $input->tools[0]->parameters);
        static::assertSame('d', $input->context[0]->description);
        static::assertSame('v', $input->context[0]->value);
        static::assertSame(['k' => 'v'], $input->state);
        static::assertSame(['a' => 1], $input->forwardedProps);
        static::assertCount(1, $input->resume);
        static::assertSame('i-1', $input->resume[0]->interruptId);
        static::assertSame('resolved', $input->resume[0]->status);
        static::assertNull($input->resume[0]->payload);
    }

    public function testReturnsParseErrorForNonObjectBody(): void
    {
        $result = (new RunAgentInputParser())->parse('"not an object"');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringContainsString('must be a JSON object', $result->message);
    }

    public function testReturnsParseErrorForInvalidJson(): void
    {
        $result = (new RunAgentInputParser())->parse('{not json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringStartsWith('Invalid JSON', $result->message);
    }

    public function testReturnsParseErrorForMissingThreadId(): void
    {
        $result = self::parse('Input/missing-thread-id.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringContainsString("Missing or invalid 'threadId'", $result->message);
    }

    public function testReturnsParseErrorForEmptyRunId(): void
    {
        $result = self::parse('Input/empty-run-id.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringContainsString("Missing or invalid 'runId'", $result->message);
    }

    public function testReturnsParseErrorForMissingMessages(): void
    {
        $result = self::parse('Input/missing-messages.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringContainsString("Missing or invalid 'messages'", $result->message);
    }

    public function testReturnsParseErrorForUnknownMessageRole(): void
    {
        $result = self::parse('Input/unknown-role.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertSame("messages[1].role 'alien' is not a recognized AG-UI message role", $result->message);
    }

    public function testReturnsParseErrorForMessageMissingId(): void
    {
        $result = self::parse('Input/message-missing-id.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertSame('messages[0].id is required', $result->message);
    }

    public function testReturnsParseErrorForToolMissingName(): void
    {
        $result = self::parse('Input/tools-missing-name.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertSame('tools[1].name is required', $result->message);
    }

    public function testReturnsParseErrorForToolMessageMissingToolCallId(): void
    {
        $result = self::parse('Input/tool-message-missing-tool-call-id.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertSame('messages[1].toolCallId is required', $result->message);
    }

    public function testReturnsParseErrorForAssistantToolCallInvalidJsonArguments(): void
    {
        $result = self::parse('Input/assistant-tool-call-invalid-arguments.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertStringStartsWith(
            'messages[1].toolCalls[0].function.arguments is not valid JSON',
            $result->message,
        );
    }

    public function testReturnsParseErrorForAssistantToolCallNonObjectArguments(): void
    {
        $result = self::parse('Input/assistant-tool-call-non-object-arguments.json');

        static::assertInstanceOf(ParseError::class, $result);
        static::assertSame(
            'messages[1].toolCalls[0].function.arguments must decode to a JSON object',
            $result->message,
        );
    }

    public function testProjectsUserMessageInputContentArrayToText(): void
    {
        $input = self::parse('Input/user-message-with-input-content.json');

        static::assertInstanceOf(RunAgentInput::class, $input);
        $first = $input->messages[0];
        static::assertInstanceOf(UserMessage::class, $first);
        static::assertSame('describe this', $first->text);
    }

    private static function parse(string $fixture): RunAgentInput|ParseError
    {
        return (new RunAgentInputParser())->parse(JsonFixture::load($fixture));
    }
}
