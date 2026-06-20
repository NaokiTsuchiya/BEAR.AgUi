<?php

declare(strict_types=1);

namespace BEAR\AgUi\Sse;

use BEAR\AgUi\Event\AgUiEventInterface;
use Generator;

/**
 * Drives an AG-UI event stream out over SSE.
 *
 * This is the SSE analogue of a BEAR TransferInterface. The critical difference
 * from BEAR.Streamer's StreamResponder:
 *
 *   BEAR.Streamer : collapses N finite streams into ONE resource, then
 *                   `while (!feof) echo fread(8192)`  -> assumes finite input.
 *
 *   here          : never collapses. `foreach ($events as $event)` pulls ONE
 *                   event at a time, frames it, writes+flushes it, then asks for
 *                   the next. Works for unbounded streams; back-pressure is the
 *                   client connection.
 *
 * The responder knows nothing about ToolUse or AG-UI semantics — it only knows
 * "iterable of AgUiEventInterface -> SSE". Boundary/lifecycle logic lives in the
 * adapter; framing in the encoder; runtime I/O in the sink.
 *
 * @psalm-type ConfirmDecision = bool
 */
final class SseResponder
{
    public function __construct(
        private readonly SseEncoder $encoder,
        private readonly SseSinkInterface $sink,
    ) {
    }

    /**
     * @param iterable<AgUiEventInterface> $events
     *
     * Accepts any iterable: a plain Generator, the adapter's Generator, or a
     * Swoole channel wrapped as a Traversable. The loop does not care which.
     */
    public function respond(iterable $events, int $statusCode = 200): void
    {
        $this->sink->open($statusCode);

        foreach ($events as $event) {
            $this->sink->write($this->encoder->encode($event));
        }

        $this->sink->close();
    }
}
