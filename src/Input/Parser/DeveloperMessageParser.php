<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Message\DeveloperMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

/**
 * Validates the body of a DeveloperMessage. Called by {@see MessageParser}
 * with the already-validated `id`. `content` (string) is required per spec.
 *
 * @internal
 */
final class DeveloperMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     *
     * @return Result<DeveloperMessage, ParseError>
     */
    public static function parseBody(string $id, array $data): Result
    {
        $content = RequireStringContent::from($data);
        if (!$content->isOk()) {
            return Result::err($content->unwrapErr());
        }

        return Result::ok(new DeveloperMessage($id, $content->unwrap()));
    }
}
