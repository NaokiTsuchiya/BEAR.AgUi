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

        static::assertSame('hits', $result->content);
        static::assertCount(1, $inner->calls);

        $outcome = $registry->resultFor('call-1');
        static::assertNotNull($outcome);
        static::assertSame('hits', $outcome->content);
        static::assertSame('{"q":"hi"}', $outcome->input);
        static::assertFalse($outcome->isError);
    }

    public function testDispatchRecordsErrorResult(): void
    {
        $inner = new FakeDispatcher();
        $inner->queueError('search', 'boom');
        $registry = new ToolCallRegistry();
        $dispatcher = new RecordingDispatcher($inner, $registry);

        $result = $dispatcher->dispatch(new ToolCall('call-1', 'search', []));

        static::assertTrue($result->isError);
        $outcome = $registry->resultFor('call-1');
        static::assertNotNull($outcome);
        static::assertTrue($outcome->isError);
        static::assertSame('boom', $outcome->content);
    }

    public function testThrowsArePropagatedAfterRecordingErrorOutcome(): void
    {
        $inner = new FakeDispatcher();
        $inner->queueThrow('search', new RuntimeException('nope'));
        $registry = new ToolCallRegistry();
        $dispatcher = new RecordingDispatcher($inner, $registry);

        $this->expectException(RuntimeException::class);
        try {
            $dispatcher->dispatch(new ToolCall('call-1', 'search', []));
        } finally {
            $outcome = $registry->resultFor('call-1');
            static::assertNotNull($outcome);
            static::assertTrue($outcome->isError);
            static::assertSame('RuntimeException: nope', $outcome->content);
        }
    }
}
