<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolCallRegistry::class)]
final class ToolCallRegistryTest extends TestCase
{
    public function testTakeStartedReturnsNullWhenEmpty(): void
    {
        $registry = new ToolCallRegistry();

        static::assertNull($registry->takeStarted('search'));
    }

    public function testStartsArePoppedPerNameInFifoOrder(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordStart('call-1', 'search');
        $registry->recordStart('call-2', 'fetch');
        $registry->recordStart('call-3', 'search');

        // Cross-name lookups are independent: taking 'fetch' does not
        // consume the older 'search' start (the old global FIFO would).
        $fetch = $registry->takeStarted('fetch');
        $firstSearch = $registry->takeStarted('search');
        $secondSearch = $registry->takeStarted('search');

        static::assertNotNull($fetch);
        static::assertNotNull($firstSearch);
        static::assertNotNull($secondSearch);
        static::assertSame('call-2', $fetch->id);
        static::assertSame('fetch', $fetch->name);
        static::assertSame('call-1', $firstSearch->id);
        static::assertSame('call-3', $secondSearch->id);
        static::assertNull($registry->takeStarted('search'));
        static::assertNull($registry->takeStarted('fetch'));
    }

    public function testAppendInputAccumulatesByIdAndIsExposedInOutcome(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordStart('call-1', 'search');
        $registry->appendInput('call-1', '{"q":');
        $registry->appendInput('call-1', '"hi"}');
        $registry->recordResult(new ToolCall('call-1', 'search', ['q' => 'hi']), ToolResult::success('call-1', 'ok'));

        $outcome = $registry->resultFor('call-1');
        static::assertNotNull($outcome);
        static::assertSame('{"q":"hi"}', $outcome->input);
        static::assertSame('ok', $outcome->content);
        static::assertFalse($outcome->isError);
    }

    public function testRecordResultFallsBackToToolCallInputWhenNoFragmentsRecorded(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(new ToolCall('call-9', 'fetch', [
            'url' => '/x',
        ]), ToolResult::success('call-9', 'body'));

        $outcome = $registry->resultFor('call-9');
        static::assertNotNull($outcome);
        static::assertSame('{"url":"/x"}', $outcome->input);
    }

    public function testStringifyNonScalarContentAsJson(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(new ToolCall('call-1', 'search', []), ToolResult::success('call-1', ['hits' => [
            'a',
            'b',
        ]]));

        $outcome = $registry->resultFor('call-1');
        static::assertNotNull($outcome);
        static::assertSame('{"hits":["a","b"]}', $outcome->content);
    }

    public function testStringifyNullContentAsEmptyString(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(new ToolCall('call-1', 'search', []), ToolResult::success('call-1', null));

        $outcome = $registry->resultFor('call-1');
        static::assertNotNull($outcome);
        static::assertSame('', $outcome->content);
    }

    public function testRecordedErrorIsExposedAsIsError(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(new ToolCall('call-1', 'search', []), ToolResult::error('call-1', 'boom'));

        $outcome = $registry->resultFor('call-1');
        static::assertNotNull($outcome);
        static::assertTrue($outcome->isError);
        static::assertSame('boom', $outcome->content);
    }

    public function testResultForReturnsNullForUnknownId(): void
    {
        $registry = new ToolCallRegistry();

        static::assertNull($registry->resultFor('never-recorded'));
    }
}
