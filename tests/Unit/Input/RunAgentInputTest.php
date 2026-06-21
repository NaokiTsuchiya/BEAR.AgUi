<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\Input;

use InvalidArgumentException;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunAgentInput::class)]
final class RunAgentInputTest extends TestCase
{
    public function testFromJsonParsesMinimalValidBody(): void
    {
        $body = '{"threadId":"t-1","runId":"r-1","messages":[{"role":"user","content":"hi"}]}';

        $input = RunAgentInput::fromJson($body);

        self::assertSame('t-1', $input->threadId);
        self::assertSame('r-1', $input->runId);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $input->messages);
        self::assertSame([], $input->tools);
        self::assertSame([], $input->resume);
        self::assertNull($input->state);
    }

    public function testFromJsonAcceptsOptionalFields(): void
    {
        $body = '{"threadId":"t","runId":"r","messages":[{"role":"user","content":"hi"}],'
            . '"tools":[{"name":"search","description":"","parameters":{}}],'
            . '"context":[{"description":"d","value":"v"}],'
            . '"state":{"k":"v"},"forwardedProps":{"a":1},'
            . '"resume":[{"interruptId":"i-1","status":"resolved"}]}';

        $input = RunAgentInput::fromJson($body);

        self::assertSame(['search'], $input->declaredToolNames());
        self::assertSame(['k' => 'v'], $input->state);
        self::assertSame(['a' => 1], $input->forwardedProps);
        self::assertCount(1, $input->resume);
        self::assertSame('i-1', $input->resume[0]['interruptId']);
    }

    public function testFromJsonRejectsNonObjectBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a JSON object');

        RunAgentInput::fromJson('"not an object"');
    }

    public function testFromJsonRejectsMissingThreadId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing or invalid 'threadId'");

        RunAgentInput::fromJson('{"runId":"r","messages":[]}');
    }

    public function testFromJsonRejectsEmptyRunId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing or invalid 'runId'");

        RunAgentInput::fromJson('{"threadId":"t","runId":"","messages":[]}');
    }

    public function testFromJsonRejectsMissingMessages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing or invalid 'messages'");

        RunAgentInput::fromJson('{"threadId":"t","runId":"r"}');
    }

    public function testLastUserMessageReturnsMostRecent(): void
    {
        $input = new RunAgentInput(
            threadId: 't',
            runId: 'r',
            messages: [
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'assistant', 'content' => 'reply'],
                ['role' => 'user', 'content' => 'second'],
            ],
        );

        self::assertSame('second', $input->lastUserMessage());
    }

    public function testLastUserMessageSkipsNonUserMessages(): void
    {
        $input = new RunAgentInput(
            threadId: 't',
            runId: 'r',
            messages: [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'tool', 'content' => 'result'],
            ],
        );

        self::assertSame('hi', $input->lastUserMessage());
    }

    public function testLastUserMessageThrowsWhenAbsent(): void
    {
        $input = new RunAgentInput(
            threadId: 't',
            runId: 'r',
            messages: [['role' => 'assistant', 'content' => 'reply']],
        );

        $this->expectException(InvalidArgumentException::class);
        $input->lastUserMessage();
    }

    public function testDeclaredToolNamesSkipsEntriesMissingName(): void
    {
        $input = new RunAgentInput(
            threadId: 't',
            runId: 'r',
            messages: [],
            tools: [
                ['name' => 'search'],
                ['description' => 'no name here'],
                ['name' => 'fetch'],
            ],
        );

        self::assertSame(['search', 'fetch'], $input->declaredToolNames());
    }
}
