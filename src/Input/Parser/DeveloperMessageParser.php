<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Message\DeveloperMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

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
     */
    public static function parseBody(string $id, array $data): DeveloperMessage|ParseError
    {
        $content = RequireStringContent::from($data);
        if ($content instanceof ParseError) {
            return $content;
        }

        return new DeveloperMessage($id, $content);
    }
}
