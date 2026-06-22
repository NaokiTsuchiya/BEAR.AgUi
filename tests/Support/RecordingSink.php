<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Support;

use NaokiTsuchiya\BEARAgUi\Sse\SseSinkInterface;
use Override;

/** SseSinkInterface double that records every open / write / close call. */
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
