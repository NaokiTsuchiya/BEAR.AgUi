<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

/**
 * Emits an SSE response on one runtime.
 *
 * This is the single seam that differs across runtimes (the S4 swap point):
 *   - PHP-FPM / CLI : http_response_code + header() + echo + flush (buffers)
 *   - Swoole        : $response->status/header/write/end
 *
 * Its sole responsibility is the runtime I/O *mechanism* — it knows nothing
 * about SSE semantics. The whole emission is a single {@see send()} so the
 * ordering (status + headers, then the body frames, then end) is internal
 * and cannot be mis-sequenced by callers; there is no `open/write/close`
 * dance to get wrong and no `headersSent` state to track.
 *
 * @api
 */
interface SseSinkInterface
{
    /**
     * Emit the response: status 200 + `$headers`, then each frame of
     * `$frames` written and flushed in order, then end-of-stream. `$frames`
     * is pulled lazily so the runtime streams one frame at a time rather
     * than buffering the whole body.
     *
     * @param array<string, string> $headers response headers (the SSE head)
     * @param iterable<string>       $frames  already-encoded SSE frames
     */
    public function send(array $headers, iterable $frames): void;
}
