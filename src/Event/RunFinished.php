<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Lifecycle: run finished. Terminal event.
 *
 * The discriminated `outcome` (success | interrupt) carries success/interrupt
 * state per AG-UI spec.
 *
 * @api
 */
final readonly class RunFinished implements AgUiEventInterface
{
    public function __construct(
        public string $threadId,
        public string $runId,
        public RunOutcome $outcome,
    ) {}

    public static function success(string $threadId, string $runId): self
    {
        return new self($threadId, $runId, RunOutcome::success());
    }

    /** @param list<Interrupt> $interrupts */
    public static function interrupt(string $threadId, string $runId, array $interrupts): self
    {
        return new self($threadId, $runId, RunOutcome::interrupt($interrupts));
    }

    #[Override]
    public function type(): string
    {
        return 'RUN_FINISHED';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            'outcome' => $this->outcome,
        ];
    }
}
