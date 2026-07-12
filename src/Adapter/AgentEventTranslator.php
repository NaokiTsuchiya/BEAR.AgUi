<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\Interrupt;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallArgs;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallEnd;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallResult;
use NaokiTsuchiya\BEARAgUi\Event\ToolCallStart;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallView;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_shift;
use function is_string;

/**
 * State machine that translates a ToolUse {@see AgentEvent} stream into the
 * AG-UI event stream the protocol expects, synthesizing the boundary events
 * AgentEvent does not carry (TEXT_MESSAGE_START/END, TOOL_CALL_START/ARGS/
 * END/RESULT, terminal RUN_FINISHED{interrupt} / RUN_ERROR).
 *
 * The lifecycle boundary (RUN_STARTED at the top, RUN_FINISHED::success at
 * the bottom, RUN_ERROR on throwable) is intentionally NOT this class's job
 * — {@see LifecycleWrapper} layers that on top.
 *
 * All per-run state lives in the local frame of {@see self::translate()}:
 * the open-text-message id, the awaiting-tool-result FIFO, the id minter
 * and the data-string decoder all stay scoped to one generator call.
 *
 * @internal
 */
final class AgentEventTranslator
{
    public function __construct(
        private readonly string $threadId,
        private readonly string $runId,
        private readonly ToolCallView $registry,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param Generator<int, AgentEvent, mixed, void> $agentStream
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     *
     * @throws Throwable re-raised from the upstream stream after the open
     *                   text block (if any) has been closed; the caller
     *                   ({@see LifecycleWrapper}) maps it to RUN_ERROR.
     */
    public function translate(Generator $agentStream): Generator
    {
        $idMinter = new IdMinter();
        $state = new TranslationState();

        try {
            foreach ($agentStream as $event) {
                yield from $this->dispatch($event, $state, $idMinter);
                if ($state->terminated) {
                    // emit* already closed the open message before flipping
                    // the flag, so nothing left to do.
                    return;
                }
            }
        } catch (\Throwable $e) {
            // Close the text block before re-throwing so the SSE consumer
            // sees TEXT_MESSAGE_END before {@see LifecycleWrapper} maps the
            // throwable to RUN_ERROR. Avoid try/finally for this — PHP
            // forbids `yield from` in a force-closed generator, which would
            // fire when the wrapper returns early on a terminal event.
            yield from $this->closeOpenMessage($state);

            throw $e;
        }

        // Normal end of stream — close any lingering text block.
        yield from $this->closeOpenMessage($state);
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function dispatch(AgentEvent $event, TranslationState $state, IdMinter $idMinter): Generator
    {
        return match ($event->type) {
            AgentEvent::TEXT_DELTA => $this->emitTextDelta($event, $state, $idMinter),
            AgentEvent::TOOL_START => $this->emitToolStart($event, $state, $idMinter),
            AgentEvent::TOOL_RESULT => $this->emitToolResult($event, $state, $idMinter),
            AgentEvent::CONFIRMATION_REQUIRED => $this->emitConfirmationInterrupt($event, $state, $idMinter),
            AgentEvent::ERROR => $this->emitInStreamError($event, $state),
            default => $this->closeOpenMessage($state),
        };
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitTextDelta(AgentEvent $event, TranslationState $state, IdMinter $idMinter): Generator
    {
        $id = $state->openMessageId;
        if ($id === null) {
            $id = $idMinter->mint('msg');
            $state->openMessageId = $id;
            yield new TextMessageStart($id, 'assistant');
        }

        yield new TextMessageContent($id, $this->dataString($event, 'text'));
    }

    /**
     * Start↔result correlation is per tool name: the AgentEvent timeline
     * only carries names, and with parallel dispatch (D29) results for
     * different names may be recorded out of start order. Within one name
     * the agent yields results in start order, so a per-name FIFO pairs
     * each result with the right id.
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    private function emitToolStart(AgentEvent $event, TranslationState $state, IdMinter $idMinter): Generator
    {
        yield from $this->closeOpenMessage($state);

        $name = $this->dataString($event, 'toolName');
        $started = $this->registry->takeStarted($name);
        $id = $started !== null ? $started->id : $idMinter->mint('tool');

        $state->awaitingResult[$name][] = $id;
        yield new ToolCallStart($id, $name, null);
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitToolResult(AgentEvent $event, TranslationState $state, IdMinter $idMinter): Generator
    {
        $name = $this->dataString($event, 'toolName');
        $queue = $state->awaitingResult[$name] ?? [];
        $id = array_shift($queue) ?? $idMinter->mint('tool');
        $state->awaitingResult[$name] = $queue;
        $outcome = $this->registry->resultFor($id);
        $input = $outcome !== null ? $outcome->input : '';
        $content = $outcome !== null ? $outcome->content : '';

        if ($input !== '') {
            yield new ToolCallArgs($id, $input);
        }

        yield new ToolCallEnd($id);
        yield new ToolCallResult($idMinter->mint('msg'), $id, $content, 'tool');
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitConfirmationInterrupt(
        AgentEvent $event,
        TranslationState $state,
        IdMinter $idMinter,
    ): Generator {
        yield from $this->closeOpenMessage($state);

        $interrupt = new Interrupt(
            id: $idMinter->mint('int'),
            reason: 'tool_confirmation',
            message: $this->dataString($event, 'message'),
            toolCallId: $this->dataString($event, 'toolId'),
            responseSchema: null,
            expiresAt: null,
            metadata: null,
        );
        yield RunFinished::interrupt($this->threadId, $this->runId, [$interrupt]);
        $state->terminated = true;
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitInStreamError(AgentEvent $event, TranslationState $state): Generator
    {
        yield from $this->closeOpenMessage($state);

        $this->logger->error('AgUiAdapter received error AgentEvent: {message}', [
            'message' => $this->dataString($event, 'message'),
        ]);
        yield new RunError('Internal agent error.', 'AGENT_ERROR');
        $state->terminated = true;
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function closeOpenMessage(TranslationState $state): Generator
    {
        if ($state->openMessageId !== null) {
            yield new TextMessageEnd($state->openMessageId);
            $state->openMessageId = null;
        }
    }

    private function dataString(AgentEvent $event, string $key): string
    {
        $value = $event->data[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
