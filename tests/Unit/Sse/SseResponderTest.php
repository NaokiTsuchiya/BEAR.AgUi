<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\Sse;

use Generator;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Sse\SseEncoder;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use NaokiTsuchiya\BEARAgUi\Sse\SseSinkInterface;
use Override;
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

        self::assertSame([200], $sink->opens);
        self::assertSame(1, $sink->closes);
        self::assertCount(2, $sink->frames);
        self::assertStringStartsWith('data: {"type":"RUN_STARTED"', $sink->frames[0]);
        self::assertStringEndsWith("\n\n", $sink->frames[0]);
        self::assertStringStartsWith('data: {"type":"TEXT_MESSAGE_CONTENT"', $sink->frames[1]);
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

        self::assertSame(
            ['open', 'yield:a', 'write', 'yield:b', 'write', 'close'],
            $log,
        );
    }
}

final class RecordingSink implements SseSinkInterface
{
    /** @var list<int> */
    public array $opens = [];
    /** @var list<string> */
    public array $frames = [];
    public int $closes = 0;

    #[Override]
    public function open(int $statusCode): void
    {
        $this->opens[] = $statusCode;
    }

    #[Override]
    public function write(string $frame): void
    {
        $this->frames[] = $frame;
    }

    #[Override]
    public function close(): void
    {
        $this->closes++;
    }
}

final class LoggingSink implements SseSinkInterface
{
    /** @param array<int, string> $log shared with the generator under test */
    public function __construct(private array &$log)
    {
    }

    #[Override]
    public function open(int $_statusCode): void
    {
        $this->log[] = 'open';
    }

    #[Override]
    public function write(string $_frame): void
    {
        $this->log[] = 'write';
    }

    #[Override]
    public function close(): void
    {
        $this->log[] = 'close';
    }
}
