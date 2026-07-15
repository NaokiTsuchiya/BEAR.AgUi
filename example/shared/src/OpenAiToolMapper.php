<?php

declare(strict_types=1);

namespace Example\Shared;

use BEAR\ToolUse\Schema\Tool;

use function array_map;

/**
 * Maps bear/tool-use tool schemas to OpenAI function-tool definitions (D20).
 *
 * Each Tool{name, description, inputSchema} becomes
 * {type: "function", function: {name, description, parameters: inputSchema}}.
 * Write-side only; the confirm/filter fields are runtime concerns and never
 * reach the OpenAI API.
 */
final readonly class OpenAiToolMapper
{
    /**
     * @param list<Tool> $tools
     *
     * @return list<array<string, mixed>> OpenAI `tools` request entries
     */
    public function map(array $tools): array
    {
        return array_map($this->mapTool(...), $tools);
    }

    /** @return array<string, mixed> */
    private function mapTool(Tool $tool): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->inputSchema,
            ],
        ];
    }
}
