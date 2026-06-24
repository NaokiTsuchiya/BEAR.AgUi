<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use function array_keys;
use function is_array;
use function is_string;

/**
 * Lenient coercion helpers used while parsing the AG-UI RunAgentInput body.
 *
 * Kept on a dedicated utility so {@see RunAgentInputParser} stays close to
 * the validation flow without picking up the cyclomatic load of the
 * lenient-input branches.
 *
 * @internal
 */
final class Coerce
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function nullableString(mixed $value): string|null
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Narrowed variant: returns `null` for missing, wrong-typed, *or* empty
     * strings so callers can collapse the `=== null || === ''` pair into a
     * single null-check and let the analyzer carry the
     * `non-empty-string` invariant downstream.
     *
     * @return non-empty-string|null
     */
    public static function nonEmptyString(mixed $value): string|null
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Validates that `$value` is an array whose keys are all strings
     * (a JSON object / TypeScript `Record<string, any>`). Returns `null`
     * for non-array values and for lists (`[0, 1, …]`) so callers can
     * distinguish "wire object" from "wire array" up-front.
     *
     * @return array<string, mixed>|null
     */
    public static function stringKeyedArray(mixed $value): array|null
    {
        if (!is_array($value)) {
            return null;
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<string, mixed>|null */
    public static function assocOrNull(mixed $value): array|null
    {
        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<string, mixed> */
    public static function assoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Coerce an arbitrary value into a list of associative arrays. Non-array
     * inputs become an empty list; non-array entries are dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function listOfObjects(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $list[] = $entry;
        }

        return $list;
    }
}
