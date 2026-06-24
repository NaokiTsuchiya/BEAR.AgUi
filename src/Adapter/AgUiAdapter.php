<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use BEAR\ToolUse\Runtime\AgentEvent;
use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallView;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public facade composing the two-layer pipeline that turns a ToolUse
 * {@see AgentEvent} stream into the AG-UI event stream the SSE responder
 * frames out.
 *
 * The state machine that synthesizes AG-UI boundary events lives in
 * {@see AgentEventTranslator}; the run-lifecycle wrap (RUN_STARTED /
 * RUN_FINISHED / RUN_ERROR) lives in {@see LifecycleWrapper}. This class
 * owns neither — it just wires them together so callers have a single
 * `run()` entry point.
 *
 * @api
 */
final class AgUiAdapter
{
    public function __construct(
        private readonly string $threadId,
        private readonly string $runId,
        private readonly ToolCallView $registry,
        private readonly LoggerInterface|null $logger,
    ) {}

    /**
     * @param Generator<int, AgentEvent, mixed, void> $agentStream
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    public function run(Generator $agentStream): Generator
    {
        $translator = new AgentEventTranslator($this->threadId, $this->runId, $this->registry, $this->logger);
        $lifecycle = new LifecycleWrapper($this->threadId, $this->runId, $this->logger);

        try {
            yield from $lifecycle->wrap($translator->translate($agentStream));
        } catch (Throwable $e) {
            // Defensive safety net: {@see LifecycleWrapper::wrap()} should
            // catch every throwable from the translator and map it to
            // RUN_ERROR. This branch only fires if the wrapper itself
            // breaks its contract.
            $this->logger?->error('AgUiAdapter caught throwable past LifecycleWrapper: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            yield new RunError('Internal agent error.', 'AGENT_ERROR');
        }
    }
}
