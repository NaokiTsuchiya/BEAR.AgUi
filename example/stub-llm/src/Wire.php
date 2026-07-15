<?php

declare(strict_types=1);

namespace Example\StubLlm;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * Lenient coercion helpers for the OpenAI-shaped wire payloads the stub
 * reads and writes. Kept separate from {@see StubRequest} / {@see StubScenario}
 * so their own cyclomatic load stays low.
 */
final class Wire
{
    /** Static reader only. */
    private function __construct() {}

    /**
     * @param mixed $value the raw `messages` value from a decoded request body
     *
     * @return list<array<string, mixed>>
     */
    public static function messages(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($value, 'is_array'));
    }

    public static function nullableString(mixed $value): string|null
    {
        return is_string($value) ? $value : null;
    }
}
