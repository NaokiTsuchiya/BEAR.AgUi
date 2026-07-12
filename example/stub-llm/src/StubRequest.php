<?php

declare(strict_types=1);

namespace Example\StubLlm;

use function array_filter;
use function array_reverse;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Read side of a /v1/chat/completions request: normalizes the wire
 * messages and answers the questions the canned conversation needs
 * (which turn is this, what did the tools return).
 *
 * The M3 app's AlpsContextInputProcessor appends a semantics user message
 * on EVERY iteration — after the tool results too — so anything that asks
 * "what came last" must skip those context messages first (they are
 * recognized by their fixed heading).
 */
final readonly class StubRequest
{
    public const ALPS_CONTEXT_HEADING = 'Application semantics from ALPS:';

    /** Static reader only. */
    private function __construct() {}

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return list<array<string, mixed>> the JSON-object entries of `messages`
     */
    public static function messages(array $requestBody): array
    {
        $messages = $requestBody['messages'] ?? null;
        if (!is_array($messages)) {
            return [];
        }

        /** @var list<array<string, mixed>> OpenAI wire messages are JSON objects */
        return array_values(array_filter($messages, is_array(...)));
    }

    /** @param list<array<string, mixed>> $messages */
    public static function isToolTurn(array $messages): bool
    {
        foreach (array_reverse($messages) as $message) {
            if (self::isAlpsContext($message)) {
                continue;
            }

            return ($message['role'] ?? null) === 'tool';
        }

        return false;
    }

    /**
     * Joins the contents of the trailing tool messages (one per completed
     * call — two for the parallel scenario), oldest first.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function trailingToolContent(array $messages): string
    {
        $contents = [];
        foreach (array_reverse($messages) as $message) {
            if (self::isAlpsContext($message)) {
                continue;
            }

            if (($message['role'] ?? null) !== 'tool') {
                break;
            }

            $content = $message['content'] ?? '';
            $contents[] = is_string($content)
                ? $content
                : json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode(' | ', array_reverse($contents));
    }

    /** @param array<string, mixed> $message */
    private static function isAlpsContext(array $message): bool
    {
        $content = $message['content'] ?? null;

        return (
            ($message['role'] ?? null) === 'user'
            && is_string($content)
            && str_starts_with($content, self::ALPS_CONTEXT_HEADING)
        );
    }
}
