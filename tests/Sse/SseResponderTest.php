<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

use Generator;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Support\LoggingSink;
use NaokiTsuchiya\BEARAgUi\Support\RecordingSink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SseResponder::class)]
final class SseResponderTest extends TestCase
{
    public function testSendsTheSseHeadersAndOneFramePerEvent(): void
    {
        $sink = new RecordingSink();
        $responder = new SseResponder(new SseEncoder());

        $events = [
            new RunStarted('t-1', 'r-1'),
            new TextMessageContent('m-1', 'hi'),
        ];

        $responder->respond($events, $sink);

        static::assertSame('text/event-stream', $sink->headers['Content-Type']);
        static::assertCount(2, $sink->frames);
        static::assertStringStartsWith('data: {"type":"RUN_STARTED"', $sink->frames[0]);
        static::assertStringEndsWith("\n\n", $sink->frames[0]);
        static::assertStringStartsWith('data: {"type":"TEXT_MESSAGE_CONTENT"', $sink->frames[1]);
    }

    /**
     * Verifies the frame stream handed to the sink is lazy: every yield is
     * followed by exactly one consumed frame before the next yield, so the
     * sink streams one frame at a time rather than buffering.
     */
    public function testFrameStreamIsConsumedLazilyOneByOne(): void
    {
        $log = [];

        $events = (static function () use (&$log): Generator {
            $log[] = 'yield:a';
            yield new TextMessageContent('m-1', 'a');
            $log[] = 'yield:b';
            yield new TextMessageContent('m-1', 'b');
        })();

        $responder = new SseResponder(new SseEncoder());

        $responder->respond($events, new LoggingSink($log));

        static::assertSame(['yield:a', 'write', 'yield:b', 'write'], $log);
    }
}
