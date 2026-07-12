<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\CliClient;

use Example\CliClient\SseFrameReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SseFrameReader::class)]
final class SseFrameReaderTest extends TestCase
{
    public function testSingleChunkContainingOneFrame(): void
    {
        $reader = new SseFrameReader();

        $frames = $reader->feed("data: {\"type\":\"RUN_STARTED\"}\n\n");

        static::assertSame(['data: {"type":"RUN_STARTED"}'], $frames);
    }

    public function testSingleChunkContainingMultipleFrames(): void
    {
        $reader = new SseFrameReader();

        $frames = $reader->feed("data: {\"type\":\"RUN_STARTED\"}\n\ndata: {\"type\":\"TEXT_MESSAGE_START\"}\n\n");

        static::assertSame(['data: {"type":"RUN_STARTED"}', 'data: {"type":"TEXT_MESSAGE_START"}'], $frames);
    }

    public function testFrameSplitAcrossTwoChunks(): void
    {
        $reader = new SseFrameReader();

        $first = $reader->feed('data: {"type":"RUN_ST');
        $second = $reader->feed("ARTED\"}\n\n");

        static::assertSame([], $first);
        static::assertSame(['data: {"type":"RUN_STARTED"}'], $second);
    }

    public function testIncompleteTrailingFrameCompletesOnNextFeed(): void
    {
        $reader = new SseFrameReader();

        $first = $reader->feed("data: {\"type\":\"RUN_STARTED\"}\n\ndata: {\"type\":\"RUN_FIN");
        $second = $reader->feed("ISHED\"}\n\n");

        static::assertSame(['data: {"type":"RUN_STARTED"}'], $first);
        static::assertSame(['data: {"type":"RUN_FINISHED"}'], $second);
    }

    public function testDecodeIgnoresEmptyAndCommentFrames(): void
    {
        $reader = new SseFrameReader();

        static::assertNull($reader->decode(''));
        static::assertNull($reader->decode(': keep-alive'));
        static::assertNull($reader->decode('data: [DONE]'));
    }

    public function testDecodeParsesJsonPayload(): void
    {
        $reader = new SseFrameReader();

        $decoded = $reader->decode('data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"m-1","delta":"hi"}');

        static::assertSame(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm-1', 'delta' => 'hi'], $decoded);
    }

    public function testDecodeSkipsLeadingCommentLineToFindDataLine(): void
    {
        $reader = new SseFrameReader();

        $decoded = $reader->decode(": keep-alive\ndata: {\"type\":\"RUN_STARTED\"}");

        static::assertSame(['type' => 'RUN_STARTED'], $decoded);
    }
}
