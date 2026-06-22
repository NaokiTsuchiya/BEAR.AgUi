<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use Generator;
use LogicException;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;

/**
 * Owns the open-text-message id while the adapter is between
 * TEXT_MESSAGE_START and TEXT_MESSAGE_END.
 *
 * Extracted from {@see AgUiAdapter} so the adapter stays focused on AgentEvent
 * → AG-UI mapping and isn't dragged past the too-many-methods threshold by
 * accumulating boundary state and its accessors.
 *
 * @internal
 */
final class OpenTextMessage
{
    private string|null $id = null;

    public function __construct(
        private readonly IdMinter $idMinter,
    ) {}

    /**
     * Open the message block if it is not already open, emitting
     * TEXT_MESSAGE_START exactly once per block.
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    public function ensure(): Generator
    {
        if ($this->id === null) {
            $this->id = $this->idMinter->mint('msg');
            yield new TextMessageStart($this->id);
        }
    }

    /**
     * @throws LogicException when no message is open — callers must run
     *                        {@see self::ensure()} first.
     */
    public function requireId(): string
    {
        if ($this->id === null) {
            throw new LogicException('No open message id; ensure() must run first.');
        }

        return $this->id;
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    public function close(): Generator
    {
        if ($this->id !== null) {
            yield new TextMessageEnd($this->id);
            $this->id = null;
        }
    }
}
