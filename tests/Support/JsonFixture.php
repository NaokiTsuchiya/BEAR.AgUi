<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use RuntimeException;

use function dirname;
use function file_get_contents;
use function is_file;

/**
 * Loads JSON fixture bodies from `tests/Fixtures/`.
 *
 * Keeps long wire-shape payloads out of test methods so the assertions
 * stay focused on behaviour, and so new scenarios add a fixture file
 * instead of a multi-line heredoc.
 */
final class JsonFixture
{
    /** @throws RuntimeException */
    public static function load(string $relativePath): string
    {
        $path = dirname(__DIR__) . '/Fixtures/' . $relativePath;
        $isFile = is_file($path);
        if (!$isFile) {
            throw new RuntimeException("Fixture not found: {$relativePath}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read fixture: {$relativePath}");
        }

        return $content;
    }
}
