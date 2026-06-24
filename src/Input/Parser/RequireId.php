<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

/**
 * Shared `id is required` check used by every per-variant message parser.
 * Pulled into its own helper because AG-UI requires `id` on *all* message
 * variants (D8) and duplicating the four-line guard in seven parsers would
 * drift over time.
 *
 * @internal
 */
final class RequireId
{
    /**
     * @param array<string, mixed> $data
     *
     * @return non-empty-string|ParseError
     */
    public static function from(array $data): string|ParseError
    {
        $id = Coerce::nonEmptyString($data['id'] ?? null);
        if ($id === null) {
            return new ParseError('id is required');
        }

        return $id;
    }
}
