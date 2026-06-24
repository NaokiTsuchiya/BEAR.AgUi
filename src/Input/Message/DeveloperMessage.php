<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use Override;

/**
 * AG-UI DeveloperMessage. Skipped by the history mapper for the same reason
 * as {@see SystemMessage}: developer-scoped instructions are not
 * conversation memory.
 *
 * @api
 */
final readonly class DeveloperMessage implements Message
{
    public function __construct(
        public string $id,
        public string $content,
    ) {}

    #[Override]
    public function role(): string
    {
        return 'developer';
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}
