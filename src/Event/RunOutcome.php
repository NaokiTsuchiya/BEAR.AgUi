<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use JsonSerializable;
use Override;

/**
 * RunFinished.outcome discriminated union: success | interrupt.
 *
 * Use the named constructors:
 *  - {@see self::success()} → `{type: "success"}`
 *  - {@see self::interrupt()} → `{type: "interrupt", interrupts: [...]}`
 *
 * @api
 */
final readonly class RunOutcome implements JsonSerializable
{
    private const TYPE_SUCCESS = 'success';
    private const TYPE_INTERRUPT = 'interrupt';

    /** @param list<Interrupt> $interrupts */
    private function __construct(
        public string $type,
        public array $interrupts = [],
    ) {}

    public static function success(): self
    {
        return new self(self::TYPE_SUCCESS);
    }

    /** @param list<Interrupt> $interrupts non-empty per AG-UI spec */
    public static function interrupt(array $interrupts): self
    {
        return new self(self::TYPE_INTERRUPT, $interrupts);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        if ($this->type === self::TYPE_INTERRUPT) {
            return ['type' => $this->type, 'interrupts' => $this->interrupts];
        }

        return ['type' => $this->type];
    }
}
