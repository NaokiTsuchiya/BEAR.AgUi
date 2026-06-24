<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SseEncoder::class)]
final class SseEncoderTest extends TestCase
{
    public function testEncodesEventAsSingleDataLineWithBlankLineTerminator(): void
    {
        $encoder = new SseEncoder();

        $frame = $encoder->encode(new RunStarted('t-1', 'r-1'));

        static::assertSame("data: {\"type\":\"RUN_STARTED\",\"threadId\":\"t-1\",\"runId\":\"r-1\"}\n\n", $frame);
    }

    public function testKeepsUtfAndSlashesUnescaped(): void
    {
        $encoder = new SseEncoder();

        $frame = $encoder->encode(new TextMessageContent('m-1', 'こんにちは / hi'));

        static::assertSame(
            "data: {\"type\":\"TEXT_MESSAGE_CONTENT\",\"messageId\":\"m-1\",\"delta\":\"こんにちは / hi\"}\n\n",
            $frame,
        );
    }
}
