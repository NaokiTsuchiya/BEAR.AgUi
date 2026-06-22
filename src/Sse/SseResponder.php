<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;

/**
 * Drives an AG-UI event stream out over SSE.
 *
 * Pulls one event at a time, frames it, writes+flushes it, then asks for the
 * next. Works for unbounded streams; back-pressure is the client connection.
 * The responder knows nothing about ToolUse or AG-UI semantics — only
 * "iterable of AgUiEventInterface -> SSE".
 *
 * @api
 */
final class SseResponder
{
    public function __construct(
        private readonly SseEncoder $encoder,
        private readonly SseSinkInterface $sink,
    ) {}

    /** @param iterable<AgUiEventInterface> $events */
    public function respond(iterable $events, int $statusCode): void
    {
        $this->sink->open($statusCode);

        foreach ($events as $event) {
            $this->sink->write($this->encoder->encode($event));
        }

        $this->sink->close();
    }
}
