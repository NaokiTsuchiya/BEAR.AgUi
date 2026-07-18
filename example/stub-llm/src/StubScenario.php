<?php

declare(strict_types=1);

namespace Example\StubLlm;

use function array_reverse;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * The canned scenarios the stub can play and their selection rule.
 *
 * The scenario is chosen from the newest human user message (skipping the
 * ALPS context message the M3 app appends — recognized by its heading,
 * since it lists every tool name and would match all keywords):
 *
 *  - contains "remind"             → reminder_put (confirm→interrupt demo)
 *  - contains "rot13" / "similar"  → rot13_get + word_similarity_get in ONE
 *                                     turn (parallel dispatch demo, D29)
 *  - anything else                 → get_time (the original M2 conversation)
 */
final readonly class StubScenario
{
    public const GET_TIME = 'get_time';
    public const PARALLEL_DEMO = 'parallel_demo';
    public const REMINDER = 'reminder';

    /** @var array<string, list<array{0: string, 1: string, 2: list<string>}>> scenario → [id, tool name, argument chunks] */
    public const TOOL_CALLS = [
        self::GET_TIME => [
            ['call_demo_1', 'get_time', ['{"timezone"', ':"UTC"}']],
        ],
        self::PARALLEL_DEMO => [
            ['call_demo_r13', 'rot13_get', ['{"text"', ':"BEAR Sunday"}']],
            ['call_demo_sim', 'word_similarity_get', ['{"a":"PHP",', '"b":"PHP8"}']],
        ],
        self::REMINDER => [
            ['call_demo_r', 'reminder_put', ['{"id":"r-1",', '"text":"buy milk"}']],
        ],
    ];

    /** @var array<string, list<string>> scenario → leading text deltas of turn 1 */
    public const LEAD_TEXT = [
        self::GET_TIME => ['Let me check ', 'the current time.'],
        self::PARALLEL_DEMO => ['Running ROT13 ', 'and comparing similarity in parallel.'],
        self::REMINDER => ['I would like to ', 'save this reminder.'],
    ];

    /** @var array<string, string> scenario → final-turn text prefix */
    public const FINAL_PREFIX = [
        self::GET_TIME => 'The current time is ',
        self::PARALLEL_DEMO => 'Gathered in parallel: ',
        self::REMINDER => 'Reminder stored: ',
    ];

    /** Selection-rule holder only. */
    private function __construct() {}

    /** @param list<array<string, mixed>> $messages OpenAI wire messages */
    public static function detect(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            $content = Wire::nullableString($message['content'] ?? null);
            if (
                ($message['role'] ?? null) !== 'user'
                || $content === null
                || str_starts_with($content, StubRequest::ALPS_CONTEXT_HEADING)
            ) {
                continue;
            }

            return self::fromKeywords(strtolower($content));
        }

        return self::GET_TIME;
    }

    private static function fromKeywords(string $text): string
    {
        if (str_contains($text, 'remind')) {
            return self::REMINDER;
        }

        if (str_contains($text, 'rot13') || str_contains($text, 'similar')) {
            return self::PARALLEL_DEMO;
        }

        return self::GET_TIME;
    }
}
