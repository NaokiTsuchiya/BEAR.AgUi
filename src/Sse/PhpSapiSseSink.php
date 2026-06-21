<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

use Override;

use function connection_aborted;
use function flush;
use function header;
use function http_response_code;
use function ob_end_flush;
use function ob_get_level;

use const PHP_SAPI;

/**
 * SSE sink for the classic PHP SAPI (php-fpm / cli-server).
 *
 * Incremental flushing on php-fpm is fiddly (output buffering, nginx
 * proxy_buffering, FastCGI buffering). The headers below disable the common
 * culprits, but a hard guarantee of byte-for-byte streaming is really a
 * Swoole-side property.
 *
 * @api
 */
final class PhpSapiSseSink implements SseSinkInterface
{
    #[Override]
    public function open(int $statusCode): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code($statusCode);
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }

    #[Override]
    public function write(string $frame): void
    {
        if (connection_aborted() === 1) {
            return;
        }

        echo $frame;
        @flush();
    }

    #[Override]
    public function close(): void
    {
        // no-op for php-fpm; the request simply ends.
    }
}
