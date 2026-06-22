<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\Event;

use JsonSerializable;
use NaokiTsuchiya\BEARAgUi\Event\Interrupt;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunOutcome;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

#[CoversClass(RunStarted::class)]
#[CoversClass(RunFinished::class)]
#[CoversClass(RunOutcome::class)]
#[CoversClass(RunError::class)]
#[CoversClass(Interrupt::class)]
final class RunLifecycleEventTest extends TestCase
{
    public function testRunStartedJson(): void
    {
        $event = new RunStarted('t-1', 'r-1');
        static::assertSame('RUN_STARTED', $event->type());
        static::assertSame('{"type":"RUN_STARTED","threadId":"t-1","runId":"r-1"}', $this->encode($event));
    }

    public function testRunFinishedSuccessJson(): void
    {
        $event = RunFinished::success('t-1', 'r-1');
        static::assertSame('RUN_FINISHED', $event->type());
        static::assertSame(
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
        static::assertSame(
            [
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
            ],
            $decoded,
        );
    }

    public function testInterruptOmitsUnsetOptionalFields(): void
    {
        $interrupt = new Interrupt(id: 'int-1', reason: 'tool_confirmation');
        static::assertSame('{"id":"int-1","reason":"tool_confirmation"}', json_encode($interrupt, JSON_THROW_ON_ERROR));
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
        static::assertSame(
            [
                'id' => 'int-1',
                'reason' => 'tool_confirmation',
                'message' => 'msg',
                'toolCallId' => 'call-1',
                'responseSchema' => ['type' => 'object'],
                'expiresAt' => '2026-01-01T00:00:00Z',
                'metadata' => ['k' => 'v'],
            ],
            $decoded,
        );
    }

    public function testRunErrorWithCode(): void
    {
        $event = new RunError('boom', 'AGENT_ERROR');
        static::assertSame('RUN_ERROR', $event->type());
        static::assertSame('{"type":"RUN_ERROR","message":"boom","code":"AGENT_ERROR"}', $this->encode($event));
    }

    public function testRunErrorWithoutCode(): void
    {
        $event = new RunError('boom');
        static::assertSame('{"type":"RUN_ERROR","message":"boom"}', $this->encode($event));
    }

    public function testRunOutcomeSuccess(): void
    {
        static::assertSame('{"type":"success"}', json_encode(RunOutcome::success(), JSON_THROW_ON_ERROR));
    }

    public function testRunOutcomeInterruptCarriesInterruptList(): void
    {
        $outcome = RunOutcome::interrupt([new Interrupt('id-1', 'tool_confirmation')]);
        static::assertSame('{"type":"interrupt","interrupts":[{"id":"id-1","reason":"tool_confirmation"}]}', json_encode(
            $outcome,
            JSON_THROW_ON_ERROR,
        ));
    }

    private function encode(JsonSerializable $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
