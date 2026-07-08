<?php

declare(strict_types=1);

namespace Example\StubLlm;

use function flush;
use function getenv;
use function is_string;
use function json_encode;
use function max;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Serializes chat.completion.chunk payloads as OpenAI-style SSE frames (D21).
 *
 * Each chunk becomes one `data: {json}\n\n` frame written via plain echo+flush
 * (deliberately independent from the library's Sse/ classes), terminated by
 * `data: [DONE]\n\n`. An optional per-chunk delay (STUB_DELAY_MS env, default 0)
 * makes progressive delivery visible during manual demos.
 *
 * Tests exercise write() under output buffering: flush() only touches the SAPI
 * buffer, so ob_get_clean() still captures every frame.
 */
final readonly class OpenAiSseWriter
{
    public function __construct(public int $delayMs = 0)
    {
    }

    /** Reads the inter-chunk delay from the STUB_DELAY_MS env var (default 0). */
    public static function fromEnv(): self
    {
        $raw = getenv('STUB_DELAY_MS');

        return new self(is_string($raw) ? max(0, (int) $raw) : 0);
    }

    /** @param iterable<int, array<string, mixed>> $chunks chat.completion.chunk payloads */
    public function write(iterable $chunks): void
    {
        foreach ($chunks as $chunk) {
            $json = json_encode($chunk, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo 'data: ' . $json . "\n\n";
            flush();

            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
        }

        echo "data: [DONE]\n\n";
        flush();
    }
}
