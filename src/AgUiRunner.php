<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi;

use BEAR\ToolUse\Runtime\AgentOptions;
use BEAR\ToolUse\Runtime\InputProcessorInterface;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInput;
use NaokiTsuchiya\BEARAgUi\ToolUse\InstrumentedAgentFactory;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;

use function array_intersect;
use function array_values;

/**
 * Host-facing facade: maps an already-parsed {@see RunAgentInput} to the
 * lazy AG-UI event stream the run produces.
 *
 * It only orchestrates — it does not render. The host frames the returned
 * events to SSE via {@see \NaokiTsuchiya\BEARAgUi\Sse\SseResponder} and a
 * sink:
 *
 *     $responder->respond($runner->stream($input), $sink);
 *
 * keeping rendering / I/O (and HTTP-status mapping) the host's concern.
 *
 * Per stream (ADR 0001, architecture §5):
 *
 *  1. Build a per-run {@see ToolCallRegistry} and hand it to the factory as
 *     the recorder, plus the seeded history (D14/D15). The input's
 *     run-readiness (a non-empty trigger message) was already validated at
 *     the parse boundary, so this stays a pure projection — connection-level
 *     failures became HTTP 400 before the host ever called stream().
 *  2. Intersect the client's declared tools with what the agent knows
 *     (D16) — empty declaration means "don't filter".
 *  3. Return the agent → adapter generator. Nothing runs until the host
 *     iterates it, so mid-stream failures surface as RUN_ERROR events, not
 *     exceptions.
 *
 * All collaborators are stateless app-singletons used directly; only the
 * registry and agent are per-run, built inside {@see stream()}.
 *
 * @api
 */
final readonly class AgUiRunner
{
    /** @param list<InputProcessorInterface> $inputProcessors ALPS safe-only etc. (D4/ADR0004) */
    public function __construct(
        private InstrumentedAgentFactory $agentFactory,
        private MessageHistoryMapper $historyMapper,
        private AgUiAdapter $adapter,
        private array $inputProcessors,
    ) {}

    /**
     * Produce the AG-UI event stream for `$input`.
     *
     * `$input` is assumed valid — parsing already rejected non-runnable
     * inputs (no/empty trigger) as HTTP 400. The returned stream is lazy:
     * the agent does not run until the host consumes it.
     *
     * @return iterable<AgUiEventInterface>
     */
    public function stream(RunAgentInput $input): iterable
    {
        $history = $this->historyMapper->map($input->history);
        $registry = new ToolCallRegistry();
        $agent = $this->agentFactory->newInstance($registry, $history);

        $options = AgentOptions::withProcessors(
            inputProcessors: $this->inputProcessors,
            enabledTools: $this->enabledTools($input->declaredToolNames),
        );

        $agentStream = $agent->runStream($input->userMessage, $options);

        return $this->adapter->run($agentStream, $input->threadId, $input->runId, $registry);
    }

    /**
     * Lenient tool intersection (D16): an empty client declaration leaves
     * the agent's tools unfiltered (an ALPS policy may still govern
     * exposure); otherwise keep only declared names the agent actually
     * knows, dropping unknown client-side tool names silently.
     *
     * @param list<string> $declared
     *
     * @return list<string>|null
     */
    private function enabledTools(array $declared): array|null
    {
        if ($declared === []) {
            return null;
        }

        return array_values(array_intersect($declared, $this->agentFactory->knownToolNames()));
    }
}
