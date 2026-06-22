<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\Event;

use JsonSerializable;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallArgs;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallEnd;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallResult;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallStart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

#[CoversClass(ToolCallStart::class)]
#[CoversClass(ToolCallArgs::class)]
#[CoversClass(ToolCallEnd::class)]
#[CoversClass(ToolCallResult::class)]
final class ToolCallEventTest extends TestCase
{
    public function testToolCallStartOmitsParentMessageIdByDefault(): void
    {
        $event = new ToolCallStart('call-1', 'search');
        static::assertSame(
            '{"type":"TOOL_CALL_START","toolCallId":"call-1","toolCallName":"search"}',
            $this->encode($event),
        );
    }

    public function testToolCallStartIncludesParentMessageId(): void
    {
        $event = new ToolCallStart('call-1', 'search', 'm-1');
        static::assertSame(
            '{"type":"TOOL_CALL_START","toolCallId":"call-1","toolCallName":"search","parentMessageId":"m-1"}',
            $this->encode($event),
        );
    }

    public function testToolCallArgs(): void
    {
        $event = new ToolCallArgs('call-1', '{"q":"hi"}');
        static::assertSame(
            '{"type":"TOOL_CALL_ARGS","toolCallId":"call-1","delta":"{\\"q\\":\\"hi\\"}"}',
            $this->encode($event),
        );
    }

    public function testToolCallEnd(): void
    {
        $event = new ToolCallEnd('call-1');
        static::assertSame('{"type":"TOOL_CALL_END","toolCallId":"call-1"}', $this->encode($event));
    }

    public function testToolCallResult(): void
    {
        $event = new ToolCallResult('m-tool-1', 'call-1', 'ok');
        static::assertSame(
            '{"type":"TOOL_CALL_RESULT","messageId":"m-tool-1","toolCallId":"call-1","content":"ok","role":"tool"}',
            $this->encode($event),
        );
    }

    private function encode(JsonSerializable $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
