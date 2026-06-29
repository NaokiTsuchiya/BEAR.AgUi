<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\ReasoningMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

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
     *
     * @return Result<ReasoningMessage, ParseError>
     */
    public static function parseBody(string $id, array $data): Result
    {
        $content = RequireStringContent::from($data);
        if (!$content->isOk()) {
            return Result::err($content->unwrapErr());
        }

        return Result::ok(
            new ReasoningMessage($id, $content->unwrap(), Coerce::nullableString($data['encryptedValue'] ?? null)),
        );
    }
}
