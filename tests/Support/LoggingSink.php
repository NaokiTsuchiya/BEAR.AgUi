<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use NaokiTsuchiya\BEARAgUi\Sse\SseSinkInterface;
use Override;

/**
 * SseSinkInterface double that appends 'open' / 'write' / 'close' to a shared
 * log so callers can assert interleave with an external producer.
 */
final class LoggingSink implements SseSinkInterface
{
    /** @param array<int, string> $log shared with the generator under test */
    public function __construct(
        private array &$log,
    ) {}

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
