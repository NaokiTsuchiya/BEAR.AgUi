<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

/**
 * Idempotent reminder upsert, exposed as the `reminder_put` tool.
 *
 * ALPS marks it `idempotent`, so the safeAndIdempotent policy keeps it
 * visible — but `confirm: true` makes the agent yield
 * CONFIRMATION_REQUIRED before execution, which the AG-UI adapter maps to
 * RUN_FINISHED{outcome: interrupt} (D4): the run ends without executing
 * the tool.
 */
final class Reminder extends ResourceObject
{
    /**
     * @param string $id   Reminder identifier (upsert key)
     * @param string $text Reminder text
     */
    #[Tool(name: 'reminder_put', description: 'Create or replace a reminder', confirm: true)]
    public function onPut(string $id, string $text): static
    {
        $this->body = [
            'saved' => true,
            'id' => $id,
            'text' => $text,
        ];

        return $this;
    }
}
