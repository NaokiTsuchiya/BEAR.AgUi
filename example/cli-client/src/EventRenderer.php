<?php

declare(strict_types=1);

namespace Example\CliClient;

use function is_array;
use function is_string;
use function mb_strimwidth;

/**
 * Renders one decoded AG-UI event to the terminal.
 *
 * Writes with plain `echo` as each event arrives — no buffering — so a
 * streaming `TEXT_MESSAGE_CONTENT.delta` shows up character-by-character
 * the way it left the server. Field names come only from
 * `docs/reference/ag-ui-protocol.md` (D30); no library type is used.
 *
 * @mago-expect lint:cyclomatic-complexity
 *
 * The event-kind dispatch in render() and its one small handler per kind
 * are what drive the count; each handler stays a few lines because the
 * alternative — one large method — is harder to follow, not easier.
 */
final class EventRenderer
{
    private const RESULT_SUMMARY_WIDTH = 200;

    /** @param array<string, mixed> $event */
    public function render(array $event): void
    {
        match ($event['type'] ?? null) {
            'TEXT_MESSAGE_CONTENT' => $this->renderTextDelta($event),
            'TOOL_CALL_START' => $this->renderToolCallStart($event),
            'TOOL_CALL_RESULT' => $this->renderToolCallResult($event),
            'RUN_FINISHED' => $this->renderRunFinished($event),
            'RUN_ERROR' => $this->renderRunError($event),
            default => null,
        };
    }

    /** @param array<string, mixed> $event */
    private function renderTextDelta(array $event): void
    {
        echo self::stringOrEmpty($event['delta'] ?? null);
    }

    /** @param array<string, mixed> $event */
    private function renderToolCallStart(array $event): void
    {
        echo "\n[tool] " . self::stringOrEmpty($event['toolCallName'] ?? null) . " ...\n";
    }

    /** @param array<string, mixed> $event */
    private function renderToolCallResult(array $event): void
    {
        $content = self::stringOrEmpty($event['content'] ?? null);
        $summary = mb_strimwidth($content, 0, self::RESULT_SUMMARY_WIDTH, '…');

        echo '[tool] ' . self::stringOrEmpty($event['toolCallId'] ?? null) . ' -> ' . $summary . "\n";
    }

    /** @param array<string, mixed> $event */
    private function renderRunFinished(array $event): void
    {
        $outcome = self::asArray($event['outcome'] ?? null);
        if ($outcome === null || ($outcome['type'] ?? null) !== 'interrupt') {
            return;
        }

        $interrupts = self::asArray($outcome['interrupts'] ?? null) ?? [];
        $interruptArrays = array_map(self::asArray(...), $interrupts);
        foreach ($interruptArrays as $interrupt) {
            if ($interrupt === null) {
                continue;
            }

            $message = self::stringOrEmpty($interrupt['message'] ?? $interrupt['reason'] ?? null);
            echo "\n[interrupt] " . $message . "\n";
        }

        echo "[interrupt] このツール呼び出しは再開できません（v1 の既知の制約）。\n";
    }

    /** @param array<string, mixed> $event */
    private function renderRunError(array $event): void
    {
        echo "\n[error] " . self::stringOrEmpty($event['message'] ?? null) . "\n";
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @return array<array-key, mixed>|null */
    private static function asArray(mixed $value): array|null
    {
        return is_array($value) ? $value : null;
    }
}
