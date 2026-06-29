<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

use function array_key_exists;
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
 * {@see \NaokiTsuchiya\BEARAgUi\Input\RunAgentInput::lastUserMessage()})
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

        $content = $data['content'];
        if (is_string($content)) {
            return Result::ok(new UserMessage($id, $content));
        }

        if (!is_array($content)) {
            return Result::err([new ParseError('content must be a string or InputContent[]')]);
        }

        return Result::ok(new UserMessage($id, self::projectText($content)));
    }

    /** @param array<array-key, mixed> $parts */
    private static function projectText(array $parts): string
    {
        $texts = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            if (($part['type'] ?? null) !== 'text') {
                continue;
            }

            $text = Coerce::nullableString($part['text'] ?? null);
            if ($text === null || $text === '') {
                continue;
            }

            $texts[] = $text;
        }

        return implode("\n", $texts);
    }
}
