<?php

declare(strict_types=1);

namespace BEAR\AgUi\Adapter;

use BEAR\AgUi\Event\AgUiEventInterface;
use BEAR\AgUi\Event\Interrupt;
use BEAR\AgUi\Event\RunError;
use BEAR\AgUi\Event\RunFinished;
use BEAR\AgUi\Event\RunStarted;
use BEAR\AgUi\Event\TextMessageContent;
use BEAR\AgUi\Event\TextMessageEnd;
use BEAR\AgUi\Event\TextMessageStart;
use BEAR\AgUi\Event\ToolCallResult;
use BEAR\AgUi\Event\ToolCallStart;
use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * Translates a ToolUse AgentEvent stream into an AG-UI event stream.
 *
 * The ToolUse side (PR #22) yields high-level AgentEvent objects:
 *   text_delta, tool_start, tool_result, completed, confirmation_required, error
 *
 * AG-UI requires more structure than ToolUse emits, so this adapter is a small
 * state machine that *generates the missing boundaries*:
 *
 *   - RUN_STARTED / RUN_FINISHED          (lifecycle, wraps the whole run)
 *   - TEXT_MESSAGE_START / _END           (ToolUse only streams text_delta; the
 *                                          message open/close boundaries are
 *                                          synthesized here)
 *
 * Boundary rules for text:
 *   - first text_delta after a non-text state  -> emit TEXT_MESSAGE_START first
 *   - any non-text event while a message is open -> emit TEXT_MESSAGE_END first
 *   - end of run while a message is open         -> close it before RUN_FINISHED
 *
 * The adapter owns NO transport concerns. It returns Generator<AgUiEvent>; the
 * SSE responder is what turns that into `data: {json}\n\n`.
 *
 * @psalm-type ConfirmDecision = bool
 */
final class AgUiAdapter
{
    /** Tracks whether a TEXT_MESSAGE block is currently open, and its id. */
    private string|null $openMessageId = null;

    public function __construct(
        private readonly string $threadId,
        private readonly string $runId,
    ) {
    }

    /**
     * @param Generator<int, AgentEvent, mixed, void> $agentStream The ToolUse runStream() generator.
     *
     * @return Generator<int, AgUiEventInterface, ConfirmDecision|null, void>
     *
     * The yielded INTERRUPT can receive a bool back via send(): the caller
     * forwards the user's approve/deny decision, which we relay into the
     * underlying ToolUse generator (which itself accepts send(false) to cancel).
     */
    public function run(Generator $agentStream): Generator
    {
        yield new RunStarted($this->threadId, $this->runId);

        try {
            while ($agentStream->valid()) {
                $event = $agentStream->current();
                $decision = yield from $this->translate($event);

                // Relay an approve/deny decision (if any) back into ToolUse.
                // ToolUse's generator accepts send(false) to cancel a tool call.
                if ($decision !== null) {
                    $agentStream->send($decision);

                    continue;
                }

                $agentStream->next();
            }

            yield from $this->closeOpenMessage();
            yield new RunFinished($this->threadId, $this->runId);
        } catch (Throwable $e) {
            yield from $this->closeOpenMessage();
            yield new RunError('AGENT_ERROR', $e->getMessage());
        }
    }

    /**
     * Translate a single AgentEvent, emitting any boundary events required.
     *
     * @return Generator<int, AgUiEventInterface, ConfirmDecision|null, ConfirmDecision|null>
     *
     * Returns a non-null decision only for CONFIRMATION_REQUIRED, where the
     * INTERRUPT we yield may carry back the user's choice via send().
     */
    private function translate(AgentEvent $event): Generator
    {
        switch ($event->type) {
            case AgentEvent::TEXT_DELTA:
                $messageId = yield from $this->ensureOpenMessage();
                yield new TextMessageContent($messageId, (string) $event->data['text']);

                return null;

            case AgentEvent::TOOL_START:
                yield from $this->closeOpenMessage();
                // ToolUse's tool_start only carries toolName; synthesize a call id.
                // (When wiring against the low-level StreamEvent::TOOL_USE_START
                // we would reuse the real id and also emit TOOL_CALL_ARGS.)
                $toolCallId = $this->newId('tool');
                yield new ToolCallStart($toolCallId, (string) $event->data['toolName']);
                // Stash id so the matching tool_result can reference it.
                $this->lastToolCallId = $toolCallId;

                return null;

            case AgentEvent::TOOL_RESULT:
                $toolCallId = $this->lastToolCallId ?? $this->newId('tool');
                yield new ToolCallResult($toolCallId, (string) ($event->data['toolName'] ?? ''));

                return null;

            case AgentEvent::CONFIRMATION_REQUIRED:
                yield from $this->closeOpenMessage();
                /** @var ConfirmDecision|null $decision */
                $decision = yield new Interrupt(
                    (string) $event->data['message'],
                    [
                        'toolName' => $event->data['toolName'] ?? null,
                        'toolId' => $event->data['toolId'] ?? null,
                        'input' => $event->data['input'] ?? [],
                    ],
                );

                return $decision;

            case AgentEvent::ERROR:
                yield from $this->closeOpenMessage();
                yield new RunError('AGENT_ERROR', (string) $event->data['message']);

                return null;

            case AgentEvent::COMPLETED:
                // ToolUse signals completion; we close any open message but defer
                // RUN_FINISHED to run() so it is emitted exactly once at the end.
                yield from $this->closeOpenMessage();

                return null;

            default:
                return null;
        }
    }

    private string|null $lastToolCallId = null;

    /**
     * Ensure a TEXT_MESSAGE block is open, emitting TEXT_MESSAGE_START if needed.
     *
     * @return Generator<int, AgUiEventInterface, mixed, string> the open message id
     */
    private function ensureOpenMessage(): Generator
    {
        if ($this->openMessageId === null) {
            $this->openMessageId = $this->newId('msg');
            yield new TextMessageStart($this->openMessageId);
        }

        return $this->openMessageId;
    }

    /**
     * Close the current TEXT_MESSAGE block if one is open.
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    private function closeOpenMessage(): Generator
    {
        if ($this->openMessageId !== null) {
            yield new TextMessageEnd($this->openMessageId);
            $this->openMessageId = null;
        }
    }

    private function newId(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }
}
