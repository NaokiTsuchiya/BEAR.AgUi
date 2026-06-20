<?php

declare(strict_types=1);

/*
 * Local stub mirroring BEAR\ToolUse\Runtime\AgentEvent from PR #22.
 *
 * This is NOT a reimplementation for production — it exists so the prototype can
 * run standalone without pulling the whole ToolUse package + an LLM backend.
 * The field/constant names are copied verbatim from PR #22 so the adapter we
 * write against this stub binds unchanged to the real class:
 *
 *   final readonly class AgentEvent {
 *     const TEXT_DELTA, TOOL_START, TOOL_RESULT, COMPLETED,
 *           CONFIRMATION_REQUIRED, ERROR;
 *     public string $type;
 *     public array  $data;
 *   }
 *
 * In the real wiring, delete this file and `use BEAR\ToolUse\Runtime\AgentEvent`.
 */

namespace BEAR\ToolUse\Runtime;

use JsonSerializable;

final readonly class AgentEvent implements JsonSerializable
{
    public const TEXT_DELTA = 'text_delta';
    public const TOOL_START = 'tool_start';
    public const TOOL_RESULT = 'tool_result';
    public const COMPLETED = 'completed';
    public const CONFIRMATION_REQUIRED = 'confirmation_required';
    public const ERROR = 'error';

    /** @param array<string, mixed> $data */
    private function __construct(
        public string $type,
        public array $data = [],
    ) {
    }

    public static function textDelta(string $text): self
    {
        return new self(self::TEXT_DELTA, ['text' => $text]);
    }

    public static function toolStart(string $toolName): self
    {
        return new self(self::TOOL_START, ['toolName' => $toolName]);
    }

    public static function toolResult(string $toolName): self
    {
        return new self(self::TOOL_RESULT, ['toolName' => $toolName]);
    }

    public static function completed(string $fullText): self
    {
        return new self(self::COMPLETED, ['fullText' => $fullText]);
    }

    /** @param array<string, mixed> $input */
    public static function confirmationRequired(string $toolName, string $toolId, array $input, string $message): self
    {
        return new self(self::CONFIRMATION_REQUIRED, [
            'toolName' => $toolName,
            'toolId' => $toolId,
            'input' => $input,
            'message' => $message,
        ]);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, ['message' => $message]);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, ...$this->data];
    }
}
