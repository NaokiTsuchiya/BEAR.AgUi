<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Result::class)]
final class ResultTest extends TestCase
{
    public function testOkHoldsTheSuccessValue(): void
    {
        $result = Result::ok('value');

        static::assertTrue($result->isOk());
        static::assertSame('value', $result->unwrap());
    }

    public function testErrHoldsTheErrorValue(): void
    {
        $error = new ParseError('boom');
        $result = Result::err([$error]);

        static::assertFalse($result->isOk());
        static::assertSame([$error], $result->unwrapErr());
    }

    public function testUnwrapOnAnErrorResultThrows(): void
    {
        $this->expectException(LogicException::class);

        Result::err([new ParseError('boom')])->unwrap();
    }

    public function testUnwrapErrOnASuccessResultThrows(): void
    {
        $this->expectException(LogicException::class);

        Result::ok('value')->unwrapErr();
    }
}
