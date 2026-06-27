<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Message\SystemMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

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
     */
    public static function parseBody(string $id, array $data): SystemMessage|ParseError
    {
        $content = RequireStringContent::from($data);
        if ($content instanceof ParseError) {
            return $content;
        }

        return new SystemMessage($id, $content);
    }
}
