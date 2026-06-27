<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Context;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

/**
 * Validates a raw AG-UI `Context` entry. `description` and `value` are both
 * required per spec.
 *
 * @internal
 */
final class ContextParser
{
    /** @param array<string, mixed> $data */
    public static function parse(array $data): Context|ParseError
    {
        $description = Coerce::nullableString($data['description'] ?? null);
        if ($description === null) {
            return new ParseError('description is required');
        }

        $value = Coerce::nullableString($data['value'] ?? null);
        if ($value === null) {
            return new ParseError('value is required');
        }

        return new Context($description, $value);
    }
}
