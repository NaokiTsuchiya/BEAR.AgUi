<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

use function array_key_exists;
use function array_walk;
use function implode;
use function is_array;
use function is_string;

/**
 * Validates the body of a UserMessage and projects `content` to plain text
 * up-front so the resulting {@see UserMessage} carries the already-typed
 * string the downstream StreamingAgent / Message::user() takes — no lazy
 * re-walking of `InputContent[]` on every access.
 *
 * Per spec, `content` is `string | InputContent[]` (D17). v1 is text-only:
 * the parser joins `type:"text"` parts with `\n` and silently drops non-
 * text parts (image/file). The empty-text case is intentionally allowed
 * here — only the response-trigger user message (caught by
 * {@see \NaokiTsuchiya\BEARAgUi\Input\RunAgentInputParser::splitTrigger()})
 * needs to be promoted to HTTP 400.
 *
 * Called by {@see MessageParser} with the already-validated `id`.
 *
 * @internal
 */
final class UserMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     *
     * @return Result<UserMessage, list<ParseError>>
     */
    public static function parseBody(string $id, array $data): Result
    {
        if (!array_key_exists('content', $data)) {
            return Result::err([new ParseError('content is required')]);
        }

        if (is_string($data['content'])) {
            return Result::ok(new UserMessage($id, $data['content']));
        }

        if (!is_array($data['content'])) {
            return Result::err([new ParseError('content must be a string or InputContent[]')]);
        }

        return Result::ok(new UserMessage($id, self::projectText($data['content'])));
    }

    /** @param array<array-key, mixed> $parts */
    private static function projectText(array $parts): string
    {
        $texts = [];
        array_walk($parts, static function (mixed $part) use (&$texts): void {
            if (!is_array($part)) {
                return;
            }

            if (($part['type'] ?? null) !== 'text') {
                return;
            }

            $text = Coerce::nullableString($part['text'] ?? null);
            if ($text === null || $text === '') {
                return;
            }

            $texts[] = $text;
        });

        return implode("\n", $texts);
    }
}
