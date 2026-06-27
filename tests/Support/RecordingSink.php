<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use NaokiTsuchiya\BEARAgUi\Sse\SseSinkInterface;
use Override;

/** SseSinkInterface double that records the headers and every frame it is sent. */
final class RecordingSink implements SseSinkInterface
{
    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<string> */
    public array $frames = [];

    #[Override]
    public function send(array $headers, iterable $frames): void
    {
        $this->headers = $headers;

        foreach ($frames as $frame) {
            $this->frames[] = $frame;
        }
    }
}
