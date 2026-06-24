<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use Override;

/**
 * AG-UI ReasoningMessage — model "scratchpad" text. Treated as observability
 * metadata (skipped by the history mapper), kept as a typed VO to preserve
 * the wire shape for callers that need it.
 *
 * @api
 */
final readonly class ReasoningMessage implements Message
{
    public function __construct(
        public string $id,
        public string $content,
        public string|null $encryptedValue,
    ) {}

    #[Override]
    public function role(): string
    {
        return 'reasoning';
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}
