<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\Event;

use NaokiTsuchiya\BEARAgUi\Event\Interrupt;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunOutcome;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallArgs;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallEnd;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallResult;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallStart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunStarted::class)]
#[CoversClass(RunFinished::class)]
#[CoversClass(RunOutcome::class)]
#[CoversClass(RunError::class)]
#[CoversClass(Interrupt::class)]
#[CoversClass(TextMessageStart::class)]
#[CoversClass(TextMessageContent::class)]
#[CoversClass(TextMessageEnd::class)]
#[CoversClass(ToolCallStart::class)]
#[CoversClass(ToolCallArgs::class)]
#[CoversClass(ToolCallEnd::class)]
#[CoversClass(ToolCallResult::class)]
final class EventSerializationTest extends TestCase
{
    public function testRunStartedJson(): void
    {
        $event = new RunStarted('t-1', 'r-1');
        self::assertSame('RUN_STARTED', $event->type());
        self::assertSame(
            '{"type":"RUN_STARTED","threadId":"t-1","runId":"r-1"}',
            $this->encode($event),
        );
    }

    public function testRunFinishedSuccessJson(): void
    {
        $event = RunFinished::success('t-1', 'r-1');
        self::assertSame('RUN_FINISHED', $event->type());
        self::assertSame(
            '{"type":"RUN_FINISHED","threadId":"t-1","runId":"r-1","outcome":{"type":"success"}}',
            $this->encode($event),
        );
    }

    public function testRunFinishedInterruptJson(): void
    {
        $interrupt = new Interrupt(
            id: 'int-1',
            reason: 'tool_confirmation',
            message: 'Approve writing 1 file?',
            toolCallId: 'call-1',
        );
        $event = RunFinished::interrupt('t-1', 'r-1', [$interrupt]);
        $decoded = json_decode($this->encode($event), true);
        self::assertSame([
            'type' => 'RUN_FINISHED',
            'threadId' => 't-1',
            'runId' => 'r-1',
            'outcome' => [
                'type' => 'interrupt',
                'interrupts' => [[
                    'id' => 'int-1',
                    'reason' => 'tool_confirmation',
                    'message' => 'Approve writing 1 file?',
                    'toolCallId' => 'call-1',
                ]],
            ],
        ], $decoded);
    }

    public function testInterruptOmitsUnsetOptionalFields(): void
    {
        $interrupt = new Interrupt(id: 'int-1', reason: 'tool_confirmation');
        self::assertSame(
            '{"id":"int-1","reason":"tool_confirmation"}',
            json_encode($interrupt, JSON_THROW_ON_ERROR),
        );
    }

    public function testInterruptIncludesAllOptionalFieldsWhenSet(): void
    {
        $interrupt = new Interrupt(
            id: 'int-1',
            reason: 'tool_confirmation',
            message: 'msg',
            toolCallId: 'call-1',
            responseSchema: ['type' => 'object'],
            expiresAt: '2026-01-01T00:00:00Z',
            metadata: ['k' => 'v'],
        );
        $decoded = json_decode(json_encode($interrupt, JSON_THROW_ON_ERROR), true);
        self::assertSame([
            'id' => 'int-1',
            'reason' => 'tool_confirmation',
            'message' => 'msg',
            'toolCallId' => 'call-1',
            'responseSchema' => ['type' => 'object'],
            'expiresAt' => '2026-01-01T00:00:00Z',
            'metadata' => ['k' => 'v'],
        ], $decoded);
    }

    public function testRunErrorWithCode(): void
    {
        $event = new RunError('boom', 'AGENT_ERROR');
        self::assertSame('RUN_ERROR', $event->type());
        self::assertSame(
            '{"type":"RUN_ERROR","message":"boom","code":"AGENT_ERROR"}',
            $this->encode($event),
        );
    }

    public function testRunErrorWithoutCode(): void
    {
        $event = new RunError('boom');
        self::assertSame(
            '{"type":"RUN_ERROR","message":"boom"}',
            $this->encode($event),
        );
    }

    public function testTextMessageStartDefaultsToAssistant(): void
    {
        $event = new TextMessageStart('m-1');
        self::assertSame(
            '{"type":"TEXT_MESSAGE_START","messageId":"m-1","role":"assistant"}',
            $this->encode($event),
        );
    }

    public function testTextMessageContent(): void
    {
        $event = new TextMessageContent('m-1', 'hi');
        self::assertSame(
            '{"type":"TEXT_MESSAGE_CONTENT","messageId":"m-1","delta":"hi"}',
            $this->encode($event),
        );
    }

    public function testTextMessageEnd(): void
    {
        $event = new TextMessageEnd('m-1');
        self::assertSame(
            '{"type":"TEXT_MESSAGE_END","messageId":"m-1"}',
            $this->encode($event),
        );
    }

    public function testToolCallStartOmitsParentMessageIdByDefault(): void
    {
        $event = new ToolCallStart('call-1', 'search');
        self::assertSame(
            '{"type":"TOOL_CALL_START","toolCallId":"call-1","toolCallName":"search"}',
            $this->encode($event),
        );
    }

    public function testToolCallStartIncludesParentMessageId(): void
    {
        $event = new ToolCallStart('call-1', 'search', 'm-1');
        self::assertSame(
            '{"type":"TOOL_CALL_START","toolCallId":"call-1","toolCallName":"search","parentMessageId":"m-1"}',
            $this->encode($event),
        );
    }

    public function testToolCallArgs(): void
    {
        $event = new ToolCallArgs('call-1', '{"q":"hi"}');
        self::assertSame(
            '{"type":"TOOL_CALL_ARGS","toolCallId":"call-1","delta":"{\\"q\\":\\"hi\\"}"}',
            $this->encode($event),
        );
    }

    public function testToolCallEnd(): void
    {
        $event = new ToolCallEnd('call-1');
        self::assertSame(
            '{"type":"TOOL_CALL_END","toolCallId":"call-1"}',
            $this->encode($event),
        );
    }

    public function testToolCallResult(): void
    {
        $event = new ToolCallResult('m-tool-1', 'call-1', 'ok');
        self::assertSame(
            '{"type":"TOOL_CALL_RESULT","messageId":"m-tool-1","toolCallId":"call-1","content":"ok","role":"tool"}',
            $this->encode($event),
        );
    }

    public function testRunOutcomeSuccess(): void
    {
        self::assertSame(
            '{"type":"success"}',
            json_encode(RunOutcome::success(), JSON_THROW_ON_ERROR),
        );
    }

    public function testRunOutcomeInterruptCarriesInterruptList(): void
    {
        $outcome = RunOutcome::interrupt([new Interrupt('id-1', 'tool_confirmation')]);
        self::assertSame(
            '{"type":"interrupt","interrupts":[{"id":"id-1","reason":"tool_confirmation"}]}',
            json_encode($outcome, JSON_THROW_ON_ERROR),
        );
    }

    private function encode(\JsonSerializable $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
