<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\ToolUse;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Llm\StreamEvent;
use NaokiTsuchiya\BEARAgUi\Tests\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingStreamingLlmClient::class)]
final class RecordingStreamingLlmClientTest extends TestCase
{
    public function testYieldsWrappedEventsVerbatimAndRecordsToolStartAndDelta(): void
    {
        $inner = new FakeStreamingLlmClient();
        $inner->queueScript([
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'hi']),
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'search']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"q":']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '"hi"}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ]);

        $registry = new ToolCallRegistry();
        $client = new RecordingStreamingLlmClient($inner, $registry);

        $observed = [];
        foreach ($client->chatStream('sys', [], []) as $event) {
            $observed[] = $event->type;
        }

        self::assertSame([
            StreamEvent::TEXT_DELTA,
            StreamEvent::TOOL_USE_START,
            StreamEvent::TOOL_USE_DELTA,
            StreamEvent::TOOL_USE_DELTA,
            StreamEvent::CONTENT_BLOCK_STOP,
            StreamEvent::MESSAGE_STOP,
        ], $observed);

        $started = $registry->nextStarted();
        self::assertNotNull($started);
        self::assertSame('call-1', $started->id);
        self::assertSame('search', $started->name);

        // recordResult drives input fragment fall-through — exercise by recording a result.
        $registry->recordResult(new ToolCall('call-1', 'search', []), ToolResult::success('call-1', 'ok'));
        $outcome = $registry->resultFor('call-1');
        self::assertNotNull($outcome);
        self::assertSame('{"q":"hi"}', $outcome->input);
    }

    public function testParallelToolUsesAreCorrelatedByContentBlockBoundary(): void
    {
        $inner = new FakeStreamingLlmClient();
        $inner->queueScript([
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-1', 'name' => 'a']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"x":1}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call-2', 'name' => 'b']),
            new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"y":2}']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
        ]);

        $registry = new ToolCallRegistry();
        $client = new RecordingStreamingLlmClient($inner, $registry);

        foreach ($client->chatStream('sys', [], []) as $_event) {
            // drain
        }

        $first = $registry->nextStarted();
        $second = $registry->nextStarted();
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('call-1', $first->id);
        self::assertSame('call-2', $second->id);

        $registry->recordResult(new ToolCall('call-1', 'a', []), ToolResult::success('call-1', ''));
        $registry->recordResult(new ToolCall('call-2', 'b', []), ToolResult::success('call-2', ''));
        $o1 = $registry->resultFor('call-1');
        $o2 = $registry->resultFor('call-2');
        self::assertNotNull($o1);
        self::assertNotNull($o2);
        self::assertSame('{"x":1}', $o1->input);
        self::assertSame('{"y":2}', $o2->input);
    }
}
