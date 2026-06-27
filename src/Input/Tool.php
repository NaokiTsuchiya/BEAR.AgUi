<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

/**
 * AG-UI Tool descriptor declared by the client on a run.
 *
 * AG-UI `tools[]` is client-provided and may include client-side tools the
 * server cannot execute (D16); the adapter intersects with what the
 * agent factory knows and silently drops the rest. This VO holds the raw
 * descriptor so callers can route by name without re-parsing the array.
 *
 * @api
 */
final readonly class Tool
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
