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
 * proxy_buffering, FastCGI buffering). The headers passed in disable the
 * common culprits, but a hard guarantee of byte-for-byte streaming is really
 * a Swoole-side property.
 *
 * @api
 */
final class PhpSapiSseSink implements SseSinkInterface
{
    #[Override]
    public function send(array $headers, iterable $frames): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(200);
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        $obLevel = ob_get_level();
        while ($obLevel > 0) {
            // @mago-expect lint:no-error-control-operator
            // ob_end_flush() emits notices when no buffer is active during shutdown; under SSE we must keep flushing.
            @ob_end_flush();
            $obLevel = ob_get_level();
        }

        foreach ($frames as $frame) {
            $aborted = connection_aborted();
            if ($aborted === 1) {
                return;
            }

            echo $frame;
            // @mago-expect lint:no-error-control-operator
            // flush() can warn when stdout is closed mid-stream; we swallow it so a dropped client doesn't crash the run.
            @flush();
        }
    }
}
