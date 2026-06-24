<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use Random\RandomException;

use function bin2hex;
use function random_bytes;

/**
 * Mints AG-UI event ids (messageId / toolCallId / interrupt id).
 *
 * Falls back to a monotonic counter if {@see random_bytes()} fails — the
 * adapter cannot abort mid-stream, so a deterministic-but-unique id is
 * preferable to letting RandomException propagate.
 *
 * @internal
 */
final class IdMinter
{
    private int $counter = 0;

    public function mint(string $prefix): string
    {
        try {
            return $prefix . '-' . bin2hex(random_bytes(6));
        } catch (RandomException) {
            $this->counter++;

            return $prefix . '-fallback-' . $this->counter;
        }
    }
}
