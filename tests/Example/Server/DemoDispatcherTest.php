<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Server;

use BEAR\ToolUse\Dispatch\ToolCall;
use Example\Server\DemoDispatcher;
use Example\Server\Tool\GetTimeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Name-branching contract of the demo dispatcher (T4/D21): get_time yields
 * a real-clock success (format asserted, not the instant — determinism is
 * out of scope per D21), unknown names yield an error result.
 */
#[CoversClass(DemoDispatcher::class)]
#[CoversClass(GetTimeTool::class)]
final class DemoDispatcherTest extends TestCase
{
    private const ATOM_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    public function testGetTimeReturnsSuccessWithCurrentUtcTime(): void
    {
        $result = (new DemoDispatcher())->dispatch(new ToolCall('call-1', 'get_time', []));

        static::assertSame('call-1', $result->toolUseId);
        static::assertFalse($result->isError);
        static::assertIsString($result->content);
        static::assertMatchesRegularExpression(self::ATOM_PATTERN, $result->content);
        static::assertStringEndsWith('+00:00', $result->content);
    }

    public function testGetTimeHonorsTimezoneArgument(): void
    {
        $result = (new DemoDispatcher())->dispatch(new ToolCall('call-2', 'get_time', ['timezone' => 'Asia/Tokyo']));

        static::assertFalse($result->isError);
        static::assertIsString($result->content);
        static::assertMatchesRegularExpression(self::ATOM_PATTERN, $result->content);
        static::assertStringEndsWith('+09:00', $result->content);
    }

    public function testGetTimeFallsBackToUtcForInvalidTimezone(): void
    {
        $result = (new DemoDispatcher())->dispatch(new ToolCall('call-3', 'get_time', ['timezone' => 'Not/AZone']));

        static::assertFalse($result->isError);
        static::assertIsString($result->content);
        static::assertStringEndsWith('+00:00', $result->content);
    }

    public function testUnknownToolReturnsErrorResult(): void
    {
        $result = (new DemoDispatcher())->dispatch(new ToolCall('call-4', 'teleport', []));

        static::assertSame('call-4', $result->toolUseId);
        static::assertTrue($result->isError);
        static::assertSame('Unknown tool: teleport', $result->content);
    }
}
