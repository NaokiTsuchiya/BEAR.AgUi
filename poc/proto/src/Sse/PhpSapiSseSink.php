<?php

declare(strict_types=1);

namespace BEAR\AgUi\Sse;

use function connection_aborted;
use function flush;
use function header;
use function http_response_code;
use function ob_get_level;

use const PHP_SAPI;

/**
 * SSE sink for the classic PHP SAPI (php-fpm / cli-server).
 *
 * NOTE: incremental flushing on php-fpm is genuinely fiddly (output buffering,
 * nginx proxy_buffering, FastCGI buffering). The headers below disable the
 * common culprits, but a hard guarantee of byte-for-byte streaming is really a
 * Swoole-side property — see SwooleSseSink (sketch) and ADR 0005. This sink is
 * good enough for local dev + the prototype's HTTP smoke test.
 */
final class PhpSapiSseSink implements SseSinkInterface
{
    public function open(int $statusCode): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code($statusCode);
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // disable nginx proxy buffering
        }

        // Collapse any output buffers so writes reach the client immediately.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }

    public function write(string $frame): void
    {
        if (connection_aborted() === 1) {
            return;
        }

        echo $frame;
        @flush();
    }

    public function close(): void
    {
        // no-op for php-fpm; the request simply ends.
    }
}
