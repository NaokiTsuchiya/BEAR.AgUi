<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

/**
 * Routes a raw AG-UI message array to the per-variant parser keyed on
 * `role`. The common `id is required` check (D8 — `id` is mandatory for
 * every variant) runs first, so each `parseBody()` only worries about the
 * role-specific shape. Variants implement {@see MessageVariantParser},
 * so PHP locks the dispatcher's expected `(id, data) -> Message|ParseError`
 * signature at class load.
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
    /** @param array<string, mixed> $data */
    public static function parse(array $data): Message|ParseError
    {
        $id = RequireId::from($data);
        if ($id instanceof ParseError) {
            return $id;
        }

        $role = Coerce::nullableString($data['role'] ?? null);

        return match ($role) {
            'user' => UserMessageParser::parseBody($id, $data),
            'assistant' => AssistantMessageParser::parseBody($id, $data),
            'tool' => ToolMessageParser::parseBody($id, $data),
            'system' => SystemMessageParser::parseBody($id, $data),
            'developer' => DeveloperMessageParser::parseBody($id, $data),
            'activity' => ActivityMessageParser::parseBody($id, $data),
            'reasoning' => ReasoningMessageParser::parseBody($id, $data),
            default => new ParseError("role '" . ($role ?? '') . "' is not a recognized AG-UI message role"),
        };
    }
}
