<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Sse;

use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Encodes a single AG-UI event into an SSE frame.
 *
 * Pure transform, no I/O. Frame shape (AG-UI default transport):
 *
 *   data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"...","delta":"hi"}\n\n
 *
 * Keeps the payload on a single `data:` line; json_encode escapes any inner
 * newlines so multi-line bookkeeping is unnecessary.
 *
 * @api
 */
final class SseEncoder
{
    public function encode(AgUiEventInterface $event): string
    {
        $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'data: ' . $json . "\n\n";
    }
}
