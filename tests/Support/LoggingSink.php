<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use NaokiTsuchiya\BEARAgUi\Sse\SseSinkInterface;
use Override;

/**
 * SseSinkInterface double that appends 'write' to a shared log as it pulls
 * each frame, so callers can assert the sink consumes the frame stream lazily
 * (interleaved with the producer) rather than buffering it.
 */
final class LoggingSink implements SseSinkInterface
{
    /** @param array<int, string> $log shared with the generator under test */
    public function __construct(
        private array &$log,
    ) {}

    #[Override]
    public function send(array $headers, iterable $frames): void
    {
        foreach ($frames as $_frame) {
            $this->log[] = 'write';
        }
    }
}
