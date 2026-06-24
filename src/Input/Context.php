<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

/**
 * AG-UI Context entry — `{ description, value }` pair. v1 does not read it
 * (host-side concern), but it is parsed into a typed VO for callers that
 * inspect `RunAgentInput.context` directly.
 *
 * @api
 */
final readonly class Context
{
    public function __construct(
        public string $description,
        public string $value,
    ) {}
}
