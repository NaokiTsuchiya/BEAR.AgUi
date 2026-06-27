<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;

/**
 * Assembles an SSE response from an AG-UI event stream and hands it to a
 * sink.
 *
 * This is the SSE-*protocol* layer: it owns the response header set and
 * turns each {@see AgUiEventInterface} into a frame (via {@see SseEncoder}),
 * then dispatches `(headers, frames)` to the {@see SseSinkInterface}. It is
 * runtime-agnostic — it never calls `header()` / `echo` / a `$response`; the
 * sink owns that. Swapping the sink (FPM ↔ Swoole) leaves this untouched,
 * and changing a header here leaves every sink untouched.
 *
 * Stateless app-singleton: it holds only the app-wide {@see SseEncoder}; the
 * per-request sink is passed to {@see respond()}.
 *
 * @api
 */
final readonly class SseResponder
{
    /** The SSE response head — protocol-defined, runtime-independent. */
    private const HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'X-Accel-Buffering' => 'no',
    ];

    public function __construct(
        private SseEncoder $encoder,
    ) {}

    /** @param iterable<AgUiEventInterface> $events */
    public function respond(iterable $events, SseSinkInterface $sink): void
    {
        $sink->send(self::HEADERS, $this->frames($events));
    }

    /**
     * @param iterable<AgUiEventInterface> $events
     *
     * @return Generator<int, string, mixed, void>
     */
    private function frames(iterable $events): Generator
    {
        foreach ($events as $event) {
            yield $this->encoder->encode($event);
        }
    }
}
