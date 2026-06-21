<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\ToolUse;

use BEAR\ToolUse\Dispatch\ToolCall;
use NaokiTsuchiya\BEARAgUi\Tests\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingDispatcher;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RecordingDispatcher::class)]
final class RecordingDispatcherTest extends TestCase
{
    public function testDispatchDelegatesAndRecordsResult(): void
    {
        $inner = new FakeDispatcher();
        $inner->queueSuccess('search', 'hits');
        $registry = new ToolCallRegistry();
        $dispatcher = new RecordingDispatcher($inner, $registry);

        $result = $dispatcher->dispatch(new ToolCall('call-1', 'search', ['q' => 'hi']));

        self::assertSame('hits', $result->content);
        self::assertCount(1, $inner->calls);

        $outcome = $registry->resultFor('call-1');
        self::assertNotNull($outcome);
        self::assertSame('hits', $outcome->content);
        self::assertSame('{"q":"hi"}', $outcome->input);
        self::assertFalse($outcome->isError);
    }

    public function testDispatchRecordsErrorResult(): void
    {
        $inner = new FakeDispatcher();
        $inner->queueError('search', 'boom');
        $registry = new ToolCallRegistry();
        $dispatcher = new RecordingDispatcher($inner, $registry);

        $result = $dispatcher->dispatch(new ToolCall('call-1', 'search', []));

        self::assertTrue($result->isError);
        $outcome = $registry->resultFor('call-1');
        self::assertNotNull($outcome);
        self::assertTrue($outcome->isError);
        self::assertSame('boom', $outcome->content);
    }

    public function testThrowsArePropagatedWithoutRecording(): void
    {
        $inner = new FakeDispatcher();
        $inner->queueThrow('search', new RuntimeException('nope'));
        $registry = new ToolCallRegistry();
        $dispatcher = new RecordingDispatcher($inner, $registry);

        $this->expectException(RuntimeException::class);
        try {
            $dispatcher->dispatch(new ToolCall('call-1', 'search', []));
        } finally {
            self::assertNull($registry->resultFor('call-1'));
        }
    }
}
