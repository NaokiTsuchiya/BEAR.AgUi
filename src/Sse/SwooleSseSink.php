<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

use Override;
use Swoole\Http\Response;

/**
 * SSE sink for Swoole (the S4 runtime swap point, D29).
 *
 * `Swoole\Http\Response::write()` sends each chunk immediately (chunked
 * transfer encoding, no userland buffer), so unlike {@see PhpSapiSseSink}
 * there is no flush dance — write-per-frame IS the incremental delivery.
 * Requires ext-swoole (declared in `suggest`); only ever constructed by
 * Swoole hosts, so the library keeps working without the extension.
 *
 * @api
 */
final readonly class SwooleSseSink implements SseSinkInterface
{
    public function __construct(
        private Response $response,
    ) {}

    #[Override]
    public function send(array $headers, iterable $frames): void
    {
        $this->response->status(200);
        foreach ($headers as $name => $value) {
            $this->response->header($name, $value);
        }

        foreach ($frames as $frame) {
            // write() returns false once the client disconnected; stop
            // pulling the (potentially long) stream in that case.
            if ($this->response->write($frame) === false) {
                return;
            }
        }

        $this->response->end();
    }
}
