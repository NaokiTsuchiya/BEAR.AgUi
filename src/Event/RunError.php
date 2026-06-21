<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Event;

use Override;

/**
 * Lifecycle: run failed during execution. Terminal event (HTTP stays 200).
 *
 * Per AG-UI spec, `message` is required and `code` is optional. The library
 * always emits `code = "AGENT_ERROR"` (see decisions.md D11).
 *
 * @api
 */
final readonly class RunError implements AgUiEventInterface
{
    public function __construct(
        public string $message,
        public string|null $code = null,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'RUN_ERROR';
    }

    /** @return array<string, string> */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->type(), 'message' => $this->message];
        if ($this->code !== null) {
            $data['code'] = $this->code;
        }

        return $data;
    }
}
