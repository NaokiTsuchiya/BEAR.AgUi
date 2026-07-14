<?php

declare(strict_types=1);

namespace Example\CliClient;

use Random\RandomException;

use function bin2hex;
use function count;
use function is_string;
use function random_bytes;
use function uniqid;

/**
 * Reconstructs AG-UI `messages[]` (wire-format associative arrays) from the
 * SSE events observed during each run.
 *
 * AG-UI is server-side stateless (the library's D15): the client is the
 * source of truth for conversation history. This is the client-side mirror
 * of the library's `MessageHistoryMapper` (`messages[]` -> ToolUse
 * `Message[]`, server-side) — here events flow the other way, into
 * `messages[]` that get resent in full on the next run. No library type is
 * used (D30); the shapes below come only from `docs/reference/ag-ui-protocol.md`.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 *
 * The event dispatch in observe() and its one small handler per AG-UI
 * event kind (text message and tool call events) are what drive both
 * counts; each handler stays a few lines because the alternative — one
 * large method — is harder to follow, not easier.
 */
final class ConversationLog
{
    /** @var list<array<string, mixed>> */
    private array $messages = [];

    private int $openAssistantIndex = -1;

    public function appendUser(string $text): void
    {
        $this->messages[] = [
            'id' => self::newId(),
            'role' => 'user',
            'content' => $text,
        ];
    }

    /** @param array<string, mixed> $event */
    public function observe(array $event): void
    {
        match ($event['type'] ?? null) {
            'TEXT_MESSAGE_START' => $this->openAssistantMessage($event),
            'TEXT_MESSAGE_CONTENT' => $this->appendAssistantContent($event),
            'TOOL_CALL_START' => $this->openToolCall($event),
            'TOOL_CALL_ARGS' => $this->appendToolCallArguments($event),
            'TOOL_CALL_RESULT' => $this->appendToolResult($event),
            'RUN_FINISHED', 'RUN_ERROR' => $this->closeOpenAssistantMessage(),
            default => null,
        };
    }

    /** @return list<array<string, mixed>> history accumulated so far, not including any in-flight trigger */
    public function toMessages(): array
    {
        return $this->messages;
    }

    /** @param array<string, mixed> $event */
    private function openAssistantMessage(array $event): void
    {
        $this->messages[] = [
            'id' => self::stringOrNewId($event['messageId'] ?? null),
            'role' => 'assistant',
            'content' => '',
        ];
        $this->openAssistantIndex = count($this->messages) - 1;
    }

    /** @param array<string, mixed> $event */
    private function appendAssistantContent(array $event): void
    {
        if ($this->openAssistantIndex < 0) {
            return;
        }

        $message = $this->messages[$this->openAssistantIndex];
        $message['content'] =
            self::stringOrEmpty($message['content'] ?? null) . self::stringOrEmpty($event['delta'] ?? null);
        $this->messages[$this->openAssistantIndex] = $message;
    }

    /** @param array<string, mixed> $event */
    private function openToolCall(array $event): void
    {
        // A tool call is not always preceded by TEXT_MESSAGE_START (a run
        // can go straight from RUN_STARTED to TOOL_CALL_START); open the
        // holding assistant message on demand in that case.
        $index = $this->currentOrNewAssistantIndex();

        /** @var list<array<string, mixed>> $toolCalls */
        $toolCalls = $this->messages[$index]['toolCalls'] ?? [];
        $toolCalls[] = [
            'id' => self::stringOrEmpty($event['toolCallId'] ?? null),
            'type' => 'function',
            'function' => [
                'name' => self::stringOrEmpty($event['toolCallName'] ?? null),
                'arguments' => '',
            ],
        ];
        $this->messages[$index]['toolCalls'] = $toolCalls;
    }

    private function currentOrNewAssistantIndex(): int
    {
        if ($this->openAssistantIndex < 0) {
            $this->messages[] = [
                'id' => self::newId(),
                'role' => 'assistant',
                'content' => '',
            ];
            $this->openAssistantIndex = count($this->messages) - 1;
        }

        return $this->openAssistantIndex;
    }

    /** @param array<string, mixed> $event */
    private function appendToolCallArguments(array $event): void
    {
        if ($this->openAssistantIndex < 0) {
            return;
        }

        $toolCallId = self::stringOrEmpty($event['toolCallId'] ?? null);
        $delta = self::stringOrEmpty($event['delta'] ?? null);

        /** @var list<array<string, mixed>> $toolCalls */
        $toolCalls = $this->messages[$this->openAssistantIndex]['toolCalls'] ?? [];
        foreach ($toolCalls as $index => $toolCall) {
            if ($toolCall['id'] !== $toolCallId) {
                continue;
            }

            /** @var array<string, mixed> $function */
            $function = $toolCall['function'];
            $function['arguments'] = self::stringOrEmpty($function['arguments'] ?? null) . $delta;
            $toolCall['function'] = $function;
            $toolCalls[$index] = $toolCall;
        }

        $this->messages[$this->openAssistantIndex]['toolCalls'] = $toolCalls;
    }

    /** @param array<string, mixed> $event */
    private function appendToolResult(array $event): void
    {
        $toolCallId = self::stringOrEmpty($event['toolCallId'] ?? null);
        $this->closeToolCallArguments($toolCallId);

        $this->messages[] = [
            'id' => self::stringOrNewId($event['messageId'] ?? null),
            'role' => 'tool',
            'content' => self::stringOrEmpty($event['content'] ?? null),
            'toolCallId' => $toolCallId,
        ];
    }

    /**
     * A tool call with no TOOL_CALL_ARGS deltas (e.g. a no-argument tool, or
     * one rejected before its args streamed) would otherwise resend an
     * empty `arguments` string — not valid JSON, and the server rejects it
     * (`function.arguments` must decode to a JSON object). Null closes
     * every tool call of the open assistant message.
     */
    private function closeToolCallArguments(string|null $toolCallId): void
    {
        if ($this->openAssistantIndex < 0) {
            return;
        }

        /** @var list<array<string, mixed>> $toolCalls */
        $toolCalls = $this->messages[$this->openAssistantIndex]['toolCalls'] ?? [];
        if ($toolCalls === []) {
            return;
        }

        foreach ($toolCalls as $index => $toolCall) {
            if ($toolCallId !== null && $toolCall['id'] !== $toolCallId) {
                continue;
            }

            /** @var array<string, mixed> $function */
            $function = $toolCall['function'];
            if (self::stringOrEmpty($function['arguments'] ?? null) === '') {
                $function['arguments'] = '{}';
                $toolCall['function'] = $function;
                $toolCalls[$index] = $toolCall;
            }
        }

        $this->messages[$this->openAssistantIndex]['toolCalls'] = $toolCalls;
    }

    /**
     * An interrupted (or errored) run ends with TOOL_CALL_START but no
     * TOOL_CALL_RESULT, so closeToolCallArguments() above never fires for
     * that call — sweep the still-open assistant message on run close, or
     * the next turn resends `arguments: ""` and the server 400s it.
     */
    private function closeOpenAssistantMessage(): void
    {
        $this->closeToolCallArguments(null);
        $this->openAssistantIndex = -1;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function stringOrNewId(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : self::newId();
    }

    private static function newId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (RandomException) {
            return uniqid('id-', true);
        }
    }
}
