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
    public function testWritesOneFramePerEventAndOpensThenClosesTheSinkOnce(): void
    {
        $sink = new RecordingSink();
        $responder = new SseResponder(new SseEncoder(), $sink);

        $events = [
            new RunStarted('t-1', 'r-1'),
            new TextMessageContent('m-1', 'hi'),
        ];

        $responder->respond($events);

        static::assertSame([200], $sink->opens);
        static::assertSame(1, $sink->closes);
        static::assertCount(2, $sink->frames);
        static::assertStringStartsWith('data: {"type":"RUN_STARTED"', $sink->frames[0]);
        static::assertStringEndsWith("\n\n", $sink->frames[0]);
        static::assertStringStartsWith('data: {"type":"TEXT_MESSAGE_CONTENT"', $sink->frames[1]);
    }

    /**
     * Verifies the responder does NOT collapse the generator: every yield is
     * followed by exactly one write before the next yield is requested.
     */
    public function testInterleavesYieldAndWriteOneByOne(): void
    {
        $log = [];

        $events = (static function () use (&$log): Generator {
            $log[] = 'yield:a';
            yield new TextMessageContent('m-1', 'a');
            $log[] = 'yield:b';
            yield new TextMessageContent('m-1', 'b');
        })();

        $sink = new LoggingSink($log);
        $responder = new SseResponder(new SseEncoder(), $sink);

        $responder->respond($events);

        static::assertSame(['open', 'yield:a', 'write', 'yield:b', 'write', 'close'], $log);
    }
}
