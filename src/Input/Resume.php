<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

/**
 * AG-UI Resume entry. Present on `RunAgentInput.resume[]` when a run is
 * restarting after an interrupt (D4); v1 accepts it for forward-compat but
 * does not act on it.
 *
 * `status` is `"resolved"` (with optional `payload`) or `"cancelled"`. A
 * missing `payload` is normalised to `null` — the AG-UI spec treats the
 * two cases the same for resolved-without-data callers.
 *
 * @api
 */
final readonly class Resume
{
    public function __construct(
        public string $interruptId,
        public string $status,
        public mixed $payload,
    ) {}
}
