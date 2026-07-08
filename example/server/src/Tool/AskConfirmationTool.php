<?php

declare(strict_types=1);

namespace Example\Server\Tool;

use BEAR\ToolUse\Schema\Tool;

/**
 * Demo tool: confirmable no-op used to demonstrate the interrupt outcome
 * (D4/D21). The stub LLM never selects it; only a real model run picks it,
 * which makes the agent stop with `RUN_FINISHED{outcome: interrupt}` before
 * the dispatcher is ever reached — so there is no execution logic here.
 */
final readonly class AskConfirmationTool
{
    public const NAME = 'ask_confirmation';

    public function definition(): Tool
    {
        return new Tool(
            self::NAME,
            'Asks the user to confirm before proceeding. Provide the question to show as `message`.',
            [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                ],
                'required' => ['message'],
            ],
            confirm: true,
        );
    }
}
