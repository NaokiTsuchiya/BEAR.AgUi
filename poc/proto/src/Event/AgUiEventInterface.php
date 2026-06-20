<?php

declare(strict_types=1);

namespace BEAR\AgUi\Event;

use JsonSerializable;

/**
 * Marker + serialization contract for every AG-UI event.
 *
 * Each event serializes to the exact JSON shape the AG-UI wire format expects.
 * The SSE encoder wraps the json in `data: {json}\n\n`; the event itself only
 * knows its own payload, never the transport framing.
 */
interface AgUiEventInterface extends JsonSerializable
{
    /** AG-UI event type discriminator, e.g. "RUN_STARTED", "TEXT_MESSAGE_CONTENT". */
    public function type(): string;
}
