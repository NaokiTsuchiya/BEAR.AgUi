<?php

declare(strict_types=1);

namespace BEAR\AgUi\Sse;

use BEAR\AgUi\Event\AgUiEventInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Encodes a single AG-UI event into an SSE frame.
 *
 * Pure transform, no I/O. This is the SSE analogue of a BEAR RenderInterface,
 * but per-event rather than per-resource: the responder calls it once for each
 * event pulled from the stream.
 *
 * Frame shape (AG-UI default transport):
 *
 *   data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"...","delta":"hi"}\n\n
 *
 * The trailing blank line terminates the SSE event. We deliberately keep the
 * payload on a single `data:` line (AG-UI events never contain raw newlines in
 * the json because json_encode escapes them), which avoids multi-line `data:`
 * bookkeeping.
 */
final class SseEncoder
{
    public function encode(AgUiEventInterface $event): string
    {
        $json = json_encode(
            $event,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return 'data: ' . $json . "\n\n";
    }
}
