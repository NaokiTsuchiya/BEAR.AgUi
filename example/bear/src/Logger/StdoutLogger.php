<?php

declare(strict_types=1);

namespace Example\Bear\Logger;

use Psr\Log\AbstractLogger;
use Stringable;

use function fwrite;
use function is_scalar;
use function print_r;
use function sprintf;
use function str_contains;
use function str_replace;

use const PHP_EOL;
use const STDOUT;

/**
 * Writes every PSR-3 log record to STDOUT so RunError causes (currently
 * swallowed by the NullLogger binding, D-less demo default) are visible in
 * the terminal running server.php instead of only showing "Internal agent
 * error." to the client.
 */
final class StdoutLogger extends AbstractLogger
{
    /** @param array<array-key, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        fwrite(STDOUT, sprintf(
            '[%s] %s%s',
            is_scalar($level) || $level instanceof Stringable ? (string) $level : print_r($level, true),
            $this->interpolate((string) $message, $context),
            PHP_EOL,
        ));
    }

    /**
     * PSR-3 context values are `mixed` by spec; is_scalar()/Stringable below
     * narrow before use.
     *
     * @param array<array-key, mixed> $context
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function interpolate(string $message, array $context): string
    {
        foreach ($context as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (!str_contains($message, $placeholder)) {
                continue;
            }

            $replacement = is_scalar($value) || $value instanceof Stringable ? (string) $value : print_r($value, true);
            $message = str_replace($placeholder, $replacement, $message);
        }

        return $message;
    }
}
