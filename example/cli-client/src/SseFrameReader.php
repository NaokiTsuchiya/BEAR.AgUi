<?php

declare(strict_types=1);

namespace Example\CliClient;

use function array_keys;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Splits an arbitrary byte stream into complete SSE frames and decodes
 * their JSON payload.
 *
 * The read-side counterpart of the library's `SseEncoder` (write side, see
 * `src/Sse/SseEncoder.php`): does not depend on it or any other library
 * type (D30). A frame is any run of bytes terminated by a blank line
 * (`\n\n`); chunk boundaries from the HTTP transport are not guaranteed to
 * line up with frame boundaries, so incomplete frames are buffered across
 * `feed()` calls.
 */
final class SseFrameReader
{
    private string $buffer = '';

    /** @return list<string> complete frames, each without the trailing blank-line terminator */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;

        $frames = [];
        $boundary = strpos($this->buffer, "\n\n");
        while ($boundary !== false) {
            $frames[] = substr($this->buffer, 0, $boundary);
            $this->buffer = substr($this->buffer, $boundary + 2);
            $boundary = strpos($this->buffer, "\n\n");
        }

        return $frames;
    }

    /** @return array<string, mixed>|null null for non-data lines (comments, `[DONE]`, blank frames) */
    public function decode(string $frame): array|null
    {
        foreach (explode("\n", $frame) as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, strlen('data:')));
            if ($payload === '' || $payload === '[DONE]') {
                return null;
            }

            return self::assocOrNull(json_decode($payload, true));
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function assocOrNull(mixed $value): array|null
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
}
