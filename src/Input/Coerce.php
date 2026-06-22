<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use function is_array;

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
    /** @return array<string, mixed>|null */
    public static function assocOrNull(mixed $value): ?array
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
