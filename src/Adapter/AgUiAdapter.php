<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\Interrupt;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
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
 * Translates a ToolUse AgentEvent stream into an AG-UI event stream.
 *
 * The ToolUse side yields six high-level AgentEvents (text_delta, tool_start,
 * tool_result, completed, confirmation_required, error). AG-UI requires more
 * structure than that, so this adapter is a small state machine that generates
 * the missing boundaries:
 *
 *   - RUN_STARTED / RUN_FINISHED               (lifecycle wraps the whole run)
 *   - TEXT_MESSAGE_START / _END                (open/close synthesized here)
 *   - TOOL_CALL_START / _ARGS / _END / _RESULT (real id + input + content pulled
 *                                               from the enrichment registry —
 *                                               see decisions.md D9/D10)
 *
 * Confirmation requests become a terminal RUN_FINISHED{outcome:interrupt}; the
 * adapter does NOT call Generator::send() on the underlying agent stream, so
 * the tool is implicitly denied (StreamingAgent's documented safe default).
 *
 * In-stream errors emit a generic RUN_ERROR while the exception itself is sent
 * to the optional logger (decisions.md D11).
 *
 * Returns Generator<AgUiEventInterface>; the SSE responder is responsible for
 * framing/transport.
 *
 * @api
 */
final class AgUiAdapter
{
    /** @var list<string> FIFO of tool-call ids awaiting a tool_result event. */
    private array $awaitingResult = [];

    /** Set true once an interrupt or in-stream error has yielded a terminal event. */
    private bool $terminated = false;

    private readonly IdMinter $idMinter;
    private readonly OpenTextMessage $openMessage;

    public function __construct(
        private readonly string $threadId,
        private readonly string $runId,
        private readonly ToolCallView $registry,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->idMinter = new IdMinter();
        $this->openMessage = new OpenTextMessage($this->idMinter);
    }

    /**
     * @param Generator<int, AgentEvent, mixed, void> $agentStream
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    public function run(Generator $agentStream): Generator
    {
        yield new RunStarted($this->threadId, $this->runId);

        try {
            foreach ($agentStream as $event) {
                yield from $this->translate($event);
                if ($this->terminated) {
                    return;
                }
            }

            yield from $this->openMessage->close();
            yield RunFinished::success($this->threadId, $this->runId);
        } catch (Throwable $e) {
            $this->logger?->error('AgUiAdapter caught throwable while consuming agent stream: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            yield from $this->openMessage->close();
            yield new RunError('Internal agent error.', 'AGENT_ERROR');
        }
    }

    /**
     * Translate a single AgentEvent, emitting any boundary events required.
     * Sets {@see self::$terminated} when the event itself ends the run
     * (interrupt / error) so the caller stops consuming further AgentEvents.
     *
     * Delegates per type to keep cyclomatic complexity bounded.
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    private function translate(AgentEvent $event): Generator
    {
        return match ($event->type) {
            AgentEvent::TEXT_DELTA => $this->emitTextDelta($event),
            AgentEvent::TOOL_START => $this->emitToolStart($event),
            AgentEvent::TOOL_RESULT => $this->emitToolResult(),
            AgentEvent::CONFIRMATION_REQUIRED => $this->emitConfirmationInterrupt($event),
            AgentEvent::ERROR => $this->emitInStreamError($event),
            default => $this->openMessage->close(),
        };
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitTextDelta(AgentEvent $event): Generator
    {
        yield from $this->openMessage->ensure();
        yield new TextMessageContent($this->openMessage->requireId(), $this->dataString($event, 'text'));
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitToolStart(AgentEvent $event): Generator
    {
        yield from $this->openMessage->close();
        $started = $this->registry->nextStarted();
        $id = $started !== null ? $started->id : $this->idMinter->mint('tool');
        $name = $started !== null ? $started->name : $this->dataString($event, 'toolName');

        $this->awaitingResult[] = $id;
        yield new ToolCallStart($id, $name);
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitToolResult(): Generator
    {
        $id = array_shift($this->awaitingResult) ?? $this->idMinter->mint('tool');
        $outcome = $this->registry->resultFor($id);
        $input = $outcome !== null ? $outcome->input : '';
        $content = $outcome !== null ? $outcome->content : '';

        if ($input !== '') {
            yield new ToolCallArgs($id, $input);
        }

        yield new ToolCallEnd($id);
        yield new ToolCallResult($this->idMinter->mint('msg'), $id, $content);
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitConfirmationInterrupt(AgentEvent $event): Generator
    {
        yield from $this->openMessage->close();
        $interrupt = new Interrupt(
            id: $this->idMinter->mint('int'),
            reason: 'tool_confirmation',
            message: $this->dataString($event, 'message'),
            toolCallId: $this->dataString($event, 'toolId'),
        );
        yield RunFinished::interrupt($this->threadId, $this->runId, [$interrupt]);
        $this->terminated = true;
    }

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
    private function emitInStreamError(AgentEvent $event): Generator
    {
        yield from $this->openMessage->close();
        $this->logger?->error('AgUiAdapter received error AgentEvent: {message}', [
            'message' => $this->dataString($event, 'message'),
        ]);
        yield new RunError('Internal agent error.', 'AGENT_ERROR');
        $this->terminated = true;
    }

    private function dataString(AgentEvent $event, string $key): string
    {
        $value = $event->data[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
