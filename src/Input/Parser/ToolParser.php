<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;
use NaokiTsuchiya\BEARAgUi\Input\Tool;

use function array_key_exists;

/**
 * Validates a raw AG-UI `Tool` entry. Per spec, `name`, `description`, and
 * `parameters` are all required; missing values raise a {@see ParseError}
 * so a malformed `tools[]` is surfaced as HTTP 400 rather than silently
 * dropped (silent drops would hide client bugs since the intersection
 * with `knownToolNames()` (D16) also drops names).
 *
 * @internal
 */
final class ToolParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @return Result<Tool, list<ParseError>>
     */
    public static function parse(array $data): Result
    {
        $errors = [];

        $name = Coerce::nonEmptyString($data['name'] ?? null);
        if ($name === null) {
            $errors[] = new ParseError('name is required');
        }

        $description = Coerce::nullableString($data['description'] ?? null);
        if ($description === null) {
            $errors[] = new ParseError('description is required');
        }

        $parameters = Coerce::stringKeyedArray($data['parameters'] ?? null);
        if ($parameters === null) {
            $errors[] = new ParseError(
                array_key_exists('parameters', $data)
                    ? 'parameters must be a JSON Schema object'
                    : 'parameters is required',
            );
        }

        if ($errors !== [] || $name === null || $description === null || $parameters === null) {
            return Result::err($errors);
        }

        return Result::ok(new Tool($name, $description, $parameters));
    }
}
