<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Message\SystemMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

/**
 * Validates the body of a SystemMessage. Called by {@see MessageParser} with
 * the already-validated `id`. `content` (string) is required per spec.
 *
 * @internal
 */
final class SystemMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     *
     * @return Result<SystemMessage, list<ParseError>>
     */
    public static function parseBody(string $id, array $data): Result
    {
        $content = RequireStringContent::from($data);
        if (!$content->isOk()) {
            return Result::err($content->unwrapErr());
        }

        return Result::ok(new SystemMessage($id, $content->unwrap()));
    }
}
