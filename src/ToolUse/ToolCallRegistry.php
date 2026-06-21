<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;

use function array_key_exists;
use function array_shift;
use function is_scalar;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Per-run side-channel for tool-call data the high-level AgentEvent stream
 * drops on the floor.
 *
 * Stores (a) a FIFO of started calls indexed by arrival order on the LLM wire
 * (`TOOL_USE_START`) and (b) a by-id map of accumulated input JSON + dispatch
 * outcome. {@see ToolCallRecorder} writes; {@see ToolCallView} reads. The
 * adapter pulls from the FIFO when it sees `tool_start`, pulls by-id when it
 * sees `tool_result` (correlating with its own awaitingResult queue).
 *
 * Lifetime: one instance per run; never shared across runs.
 *
 * @api
 */
final class ToolCallRegistry implements ToolCallRecorder, ToolCallView
{
    /** @var list<StartedToolCall> FIFO of started calls in wire arrival order. */
    private array $started = [];

    /** @var array<string, string> id → accumulated input JSON fragments. */
    private array $inputs = [];

    /** @var array<string, ToolCallOutcome> id → recorded dispatch outcome. */
    private array $outcomes = [];

    #[Override]
    public function recordStart(string $id, string $name): void
    {
        $this->started[] = new StartedToolCall($id, $name);
        $this->inputs[$id] = '';
    }

    #[Override]
    public function appendInput(string $id, string $delta): void
    {
        $this->inputs[$id] = ($this->inputs[$id] ?? '') . $delta;
    }

    #[Override]
    public function recordResult(ToolCall $call, ToolResult $result): void
    {
        $input = $this->inputs[$call->id] ?? $this->encodeInput($call->input);
        $this->outcomes[$call->id] = new ToolCallOutcome(
            input: $input,
            content: $this->stringify($result->content),
            isError: $result->isError,
        );
    }

    #[Override]
    public function nextStarted(): StartedToolCall|null
    {
        if ($this->started === []) {
            return null;
        }

        return array_shift($this->started);
    }

    #[Override]
    public function resultFor(string $id): ToolCallOutcome|null
    {
        if (! array_key_exists($id, $this->outcomes)) {
            return null;
        }

        return $this->outcomes[$id];
    }

    /** @param array<string, mixed> $input */
    private function encodeInput(array $input): string
    {
        return json_encode(
            $input,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function stringify(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if ($content === null) {
            return '';
        }

        if (is_scalar($content)) {
            return (string) $content;
        }

        return json_encode(
            $content,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
