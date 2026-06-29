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
     * @return Result<Tool, ParseError>
     */
    public static function parse(array $data): Result
    {
        $name = Coerce::nonEmptyString($data['name'] ?? null);
        if ($name === null) {
            return Result::err(new ParseError('name is required'));
        }

        $description = Coerce::nullableString($data['description'] ?? null);
        if ($description === null) {
            return Result::err(new ParseError('description is required'));
        }

        if (!array_key_exists('parameters', $data)) {
            return Result::err(new ParseError('parameters is required'));
        }

        $parameters = Coerce::stringKeyedArray($data['parameters']);
        if ($parameters === null) {
            return Result::err(new ParseError('parameters must be a JSON Schema object'));
        }

        return Result::ok(new Tool($name, $description, $parameters));
    }
}
