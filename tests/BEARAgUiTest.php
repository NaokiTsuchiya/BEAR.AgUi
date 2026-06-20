<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi;

use PHPUnit\Framework\TestCase;

final class BEARAgUiTest extends TestCase
{
    protected BEARAgUi $bEARAgUi;

    protected function setUp(): void
    {
        $this->bEARAgUi = new BEARAgUi();
    }

    public function testIsInstanceOfBEARAgUi(): void
    {
        $actual = $this->bEARAgUi;
        static::assertInstanceOf(BEARAgUi::class, $actual);
    }
}
