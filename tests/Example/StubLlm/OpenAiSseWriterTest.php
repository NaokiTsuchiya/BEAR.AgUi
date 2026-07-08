<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\StubLlm;

use Example\StubLlm\OpenAiSseWriter;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;
use function putenv;

/**
 * The writer echoes directly (inherent to the stub, D21), so framing is
 * asserted through output buffering: flush() only touches the SAPI buffer
 * and never drains a user-level ob_start() buffer.
 */
final class OpenAiSseWriterTest extends TestCase
{
    public function testFramesEachChunkAsDataLineAndTerminatesWithDone(): void
    {
        ob_start();
        (new OpenAiSseWriter())->write([['a' => 1], ['b' => 2]]);
        $output = (string) ob_get_clean();

        static::assertSame("data: {\"a\":1}\n\ndata: {\"b\":2}\n\ndata: [DONE]\n\n", $output);
    }

    public function testEmptyStreamStillTerminatesWithDone(): void
    {
        ob_start();
        (new OpenAiSseWriter())->write([]);
        $output = (string) ob_get_clean();

        static::assertSame("data: [DONE]\n\n", $output);
    }

    public function testFromEnvReadsStubDelayMsWithZeroDefault(): void
    {
        putenv('STUB_DELAY_MS=25');
        try {
            static::assertSame(25, OpenAiSseWriter::fromEnv()->delayMs);
        } finally {
            putenv('STUB_DELAY_MS');
        }

        static::assertSame(0, OpenAiSseWriter::fromEnv()->delayMs);
    }
}
