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
use function bin2hex;
use function is_string;
use function random_bytes;

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
    private string|null $openMessageId = null;

    /** @var list<string> FIFO of tool-call ids awaiting a tool_result event. */
    private array $awaitingResult = [];

    public function __construct(
        private readonly string $threadId,
        private readonly string $runId,
        private readonly ToolCallView $registry,
        private readonly LoggerInterface|null $logger = null,
    ) {
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
                $terminate = yield from $this->translate($event);
                if ($terminate === true) {
                    return;
                }
            }

            yield from $this->closeOpenMessage();
            yield RunFinished::success($this->threadId, $this->runId);
        } catch (Throwable $e) {
            $this->logger?->error(
                'AgUiAdapter caught throwable while consuming agent stream: {message}',
                ['message' => $e->getMessage(), 'exception' => $e],
            );
            yield from $this->closeOpenMessage();
            yield new RunError('Internal agent error.', 'AGENT_ERROR');
        }
    }

    /**
     * Translate a single AgentEvent, emitting any boundary events required.
     * Returns true when the run is terminated by this event (interrupt / error)
     * so the caller stops consuming further AgentEvents.
     *
     * @return Generator<int, AgUiEventInterface, mixed, bool>
     */
    private function translate(AgentEvent $event): Generator
    {
        switch ($event->type) {
            case AgentEvent::TEXT_DELTA:
                $messageId = yield from $this->ensureOpenMessage();
                yield new TextMessageContent($messageId, $this->dataString($event, 'text'));

                return false;

            case AgentEvent::TOOL_START:
                yield from $this->closeOpenMessage();
                $started = $this->registry->nextStarted();
                if ($started !== null) {
                    $id = $started->id;
                    $name = $started->name;
                } else {
                    $id = $this->newId('tool');
                    $name = $this->dataString($event, 'toolName');
                }

                $this->awaitingResult[] = $id;
                yield new ToolCallStart($id, $name);

                return false;

            case AgentEvent::TOOL_RESULT:
                $id = array_shift($this->awaitingResult) ?? $this->newId('tool');
                $outcome = $this->registry->resultFor($id);
                $input = $outcome !== null ? $outcome->input : '';
                $content = $outcome !== null ? $outcome->content : '';

                if ($input !== '') {
                    yield new ToolCallArgs($id, $input);
                }

                yield new ToolCallEnd($id);
                yield new ToolCallResult($this->newId('msg'), $id, $content);

                return false;

            case AgentEvent::CONFIRMATION_REQUIRED:
                yield from $this->closeOpenMessage();
                $interrupt = new Interrupt(
                    id: $this->newId('int'),
                    reason: 'tool_confirmation',
                    message: $this->dataString($event, 'message'),
                    toolCallId: $this->dataString($event, 'toolId'),
                );
                yield RunFinished::interrupt($this->threadId, $this->runId, [$interrupt]);

                return true;

            case AgentEvent::ERROR:
                yield from $this->closeOpenMessage();
                $this->logger?->error(
                    'AgUiAdapter received error AgentEvent: {message}',
                    ['message' => $this->dataString($event, 'message')],
                );
                yield new RunError('Internal agent error.', 'AGENT_ERROR');

                return true;

            case AgentEvent::COMPLETED:
                yield from $this->closeOpenMessage();

                return false;

            default:
                return false;
        }
    }

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

    /** @return Generator<int, AgUiEventInterface, mixed, void> */
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

    private function dataString(AgentEvent $event, string $key): string
    {
        $value = $event->data[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
