<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Lifecycle: run started. Always the first event of a run.
 *
 * @api
 */
final readonly class RunStarted implements AgUiEventInterface
{
    public function __construct(
        public string $threadId,
        public string $runId,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'RUN_STARTED';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'threadId' => $this->threadId, 'runId' => $this->runId];
    }
}
