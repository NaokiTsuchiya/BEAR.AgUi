<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\ReasoningMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

/**
 * Validates the body of a ReasoningMessage. Called by {@see MessageParser}
 * with the already-validated `id`. `content` (string) is required per spec;
 * `encryptedValue` is optional.
 *
 * @internal
 */
final class ReasoningMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     */
    public static function parseBody(string $id, array $data): ReasoningMessage|ParseError
    {
        $content = RequireStringContent::from($data);
        if ($content instanceof ParseError) {
            return $content;
        }

        return new ReasoningMessage($id, $content, Coerce::nullableString($data['encryptedValue'] ?? null));
    }
}
