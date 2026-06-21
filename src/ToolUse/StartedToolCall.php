<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

/**
 * One element of the FIFO of tools the LLM has started invoking.
 *
 * @api
 */
final readonly class StartedToolCall
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
