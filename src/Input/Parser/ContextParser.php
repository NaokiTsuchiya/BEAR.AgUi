<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Context;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

/**
 * Validates a raw AG-UI `Context` entry. `description` and `value` are both
 * required per spec.
 *
 * @internal
 */
final class ContextParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @return Result<Context, list<ParseError>>
     */
    public static function parse(array $data): Result
    {
        $errors = [];

        $description = Coerce::nullableString($data['description'] ?? null);
        if ($description === null) {
            $errors[] = new ParseError('description is required');
        }

        $value = Coerce::nullableString($data['value'] ?? null);
        if ($value === null) {
            $errors[] = new ParseError('value is required');
        }

        if ($errors !== [] || $description === null || $value === null) {
            return Result::err($errors);
        }

        return Result::ok(new Context($description, $value));
    }
}
