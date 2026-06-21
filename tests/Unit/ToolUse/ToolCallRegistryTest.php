<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\ToolUse;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolCallRegistry::class)]
final class ToolCallRegistryTest extends TestCase
{
    public function testNextStartedReturnsNullWhenEmpty(): void
    {
        $registry = new ToolCallRegistry();

        self::assertNull($registry->nextStarted());
    }

    public function testStartsArePoppedInFifoOrder(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordStart('call-1', 'search');
        $registry->recordStart('call-2', 'fetch');

        $first = $registry->nextStarted();
        $second = $registry->nextStarted();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('call-1', $first->id);
        self::assertSame('search', $first->name);
        self::assertSame('call-2', $second->id);
        self::assertSame('fetch', $second->name);
        self::assertNull($registry->nextStarted());
    }

    public function testAppendInputAccumulatesByIdAndIsExposedInOutcome(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordStart('call-1', 'search');
        $registry->appendInput('call-1', '{"q":');
        $registry->appendInput('call-1', '"hi"}');
        $registry->recordResult(
            new ToolCall('call-1', 'search', ['q' => 'hi']),
            ToolResult::success('call-1', 'ok'),
        );

        $outcome = $registry->resultFor('call-1');
        self::assertNotNull($outcome);
        self::assertSame('{"q":"hi"}', $outcome->input);
        self::assertSame('ok', $outcome->content);
        self::assertFalse($outcome->isError);
    }

    public function testRecordResultFallsBackToToolCallInputWhenNoFragmentsRecorded(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(
            new ToolCall('call-9', 'fetch', ['url' => '/x']),
            ToolResult::success('call-9', 'body'),
        );

        $outcome = $registry->resultFor('call-9');
        self::assertNotNull($outcome);
        self::assertSame('{"url":"/x"}', $outcome->input);
    }

    public function testStringifyNonScalarContentAsJson(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(
            new ToolCall('call-1', 'search', []),
            ToolResult::success('call-1', ['hits' => ['a', 'b']]),
        );

        $outcome = $registry->resultFor('call-1');
        self::assertNotNull($outcome);
        self::assertSame('{"hits":["a","b"]}', $outcome->content);
    }

    public function testStringifyNullContentAsEmptyString(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(
            new ToolCall('call-1', 'search', []),
            ToolResult::success('call-1', null),
        );

        $outcome = $registry->resultFor('call-1');
        self::assertNotNull($outcome);
        self::assertSame('', $outcome->content);
    }

    public function testRecordedErrorIsExposedAsIsError(): void
    {
        $registry = new ToolCallRegistry();

        $registry->recordResult(
            new ToolCall('call-1', 'search', []),
            ToolResult::error('call-1', 'boom'),
        );

        $outcome = $registry->resultFor('call-1');
        self::assertNotNull($outcome);
        self::assertTrue($outcome->isError);
        self::assertSame('boom', $outcome->content);
    }

    public function testResultForReturnsNullForUnknownId(): void
    {
        $registry = new ToolCallRegistry();

        self::assertNull($registry->resultFor('never-recorded'));
    }
}
