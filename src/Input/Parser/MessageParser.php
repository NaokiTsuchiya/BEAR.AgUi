<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

/**
 * Routes a raw AG-UI message array to the per-variant parser keyed on
 * `role`. The common `id is required` check (D8 — `id` is mandatory for
 * every variant) runs first, so each `parseBody()` only worries about the
 * role-specific shape. Variants implement {@see MessageVariantParser},
 * so PHP locks the dispatcher's expected
 * `(id, data) -> Result<Message, ParseError>` signature at class load.
 *
 * Unknown / missing roles return a {@see ParseError} (HTTP 400). Silently
 * dropping them would hide client typos (`"userr"`) as data loss; the
 * trade-off is that adding new roles in a future AG-UI spec revision
 * requires updating this match before the new variant is accepted.
 *
 * @internal
 */
final class MessageParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @return Result<Message, ParseError>
     */
    public static function parse(array $data): Result
    {
        $id = RequireId::from($data);
        if (!$id->isOk()) {
            return Result::err($id->unwrapErr());
        }

        $idValue = $id->unwrap();
        $role = Coerce::nullableString($data['role'] ?? null);

        return match ($role) {
            'user' => UserMessageParser::parseBody($idValue, $data),
            'assistant' => AssistantMessageParser::parseBody($idValue, $data),
            'tool' => ToolMessageParser::parseBody($idValue, $data),
            'system' => SystemMessageParser::parseBody($idValue, $data),
            'developer' => DeveloperMessageParser::parseBody($idValue, $data),
            'activity' => ActivityMessageParser::parseBody($idValue, $data),
            'reasoning' => ReasoningMessageParser::parseBody($idValue, $data),
            default => Result::err(
                new ParseError("role '" . ($role ?? '') . "' is not a recognized AG-UI message role"),
            ),
        };
    }
}
