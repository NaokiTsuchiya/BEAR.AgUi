<?php

declare(strict_types=1);

namespace Example\Shared;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Converts a single bear content block into its OpenAI request payload;
 * {@see OpenAiMessageMapper} orchestrates these per message (D20).
 *
 * Blocks are read defensively (null coalescing) because bear types them as
 * plain arrays — a missing key must degrade, not raise.
 */
final readonly class OpenAiContentBlockMapper
{
    /** @param array<string, mixed> $block */
    public function isType(array $block, string $type): bool
    {
        return ($block['type'] ?? null) === $type;
    }

    /** @param array<string, mixed> $block */
    public function text(array $block): string
    {
        $isText = $this->isType($block, 'text');
        if (!$isText) {
            return '';
        }

        return $this->stringOrEmpty($block['text'] ?? null);
    }

    /**
     * tool_use block → one `tool_calls` request entry.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public function toolCall(array $block): array
    {
        return [
            'id' => $block['id'] ?? '',
            'type' => 'function',
            'function' => [
                'name' => $block['name'] ?? '',
                'arguments' => $this->encodeArguments($block['input'] ?? []),
            ],
        ];
    }

    /**
     * tool_result block → one {role: tool} message (OpenAI wants one tool
     * message per call; content must be a string, so non-string results
     * are json_encoded).
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public function toolResult(array $block): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $block['tool_use_id'] ?? '',
            'content' => $this->stringOrEncoded($block['content'] ?? ''),
        ];
    }

    /** Tool-call arguments are a JSON object string; an empty input encodes as {} (not []). */
    private function encodeArguments(mixed $input): string
    {
        return $input === [] ? '{}' : $this->encode($input);
    }

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function stringOrEncoded(mixed $value): string
    {
        return is_string($value) ? $value : $this->encode($value);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
