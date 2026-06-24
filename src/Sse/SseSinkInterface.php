<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

/**
 * Abstracts the act of writing+flushing one SSE frame.
 *
 * This is the single seam that differs across runtimes:
 *   - PHP-FPM / CLI : echo + flush (plus output-buffer wrangling)
 *   - Swoole        : $response->write($chunk)   (no echo, immediate send)
 *
 * The responder loop stays identical; only the sink is swapped via DI.
 *
 * @api
 */
interface SseSinkInterface
{
    /** Emit HTTP status + SSE headers. Called once, before any frame. */
    public function open(int $statusCode): void;

    /** Write one already-encoded SSE frame and flush it to the client. */
    public function write(string $frame): void;

    /** Optional end-of-stream hook (Swoole needs $response->end()). */
    public function close(): void;
}
