<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;

/**
 * Owns the open-text-message id while the adapter is between
 * TEXT_MESSAGE_START and TEXT_MESSAGE_END.
 *
 * The open/closed state machine is internal: callers have only two atomic
 * operations — {@see self::emitContent()} (opens the block if needed, then
 * emits CONTENT) and {@see self::close()} (closes if open). Neither depends
 * on the caller having issued the other in any particular order, and the
 * id is never exposed to callers.
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
     * Emit a TEXT_MESSAGE_CONTENT delta, opening a new message block first
     * if none is currently open (yielding TEXT_MESSAGE_START before the
     * CONTENT).
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    public function emitContent(string $delta): Generator
    {
        $id = $this->id;
        if ($id === null) {
            $id = $this->idMinter->mint('msg');
            $this->id = $id;
            yield new TextMessageStart($id, 'assistant');
        }

        yield new TextMessageContent($id, $delta);
    }

    /**
     * Close the currently open message block (yielding TEXT_MESSAGE_END).
     * No-op when nothing is open.
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    public function close(): Generator
    {
        if ($this->id !== null) {
            yield new TextMessageEnd($this->id);
            $this->id = null;
        }
    }
}
