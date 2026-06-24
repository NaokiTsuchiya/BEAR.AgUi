<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
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
    /** @param array<string, mixed> $data */
    public static function parse(array $data): Tool|ParseError
    {
        $name = Coerce::nonEmptyString($data['name'] ?? null);
        if ($name === null) {
            return new ParseError('name is required');
        }

        $description = Coerce::nullableString($data['description'] ?? null);
        if ($description === null) {
            return new ParseError('description is required');
        }

        if (!array_key_exists('parameters', $data)) {
            return new ParseError('parameters is required');
        }

        $parameters = Coerce::stringKeyedArray($data['parameters']);
        if ($parameters === null) {
            return new ParseError('parameters must be a JSON Schema object');
        }

        return new Tool($name, $description, $parameters);
    }
}
