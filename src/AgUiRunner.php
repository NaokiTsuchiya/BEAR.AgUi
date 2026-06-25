<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi;

use BEAR\ToolUse\Runtime\AgentOptions;
use BEAR\ToolUse\Runtime\InputProcessorInterface;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInput;
use NaokiTsuchiya\BEARAgUi\Sse\SseEncoder;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use NaokiTsuchiya\BEARAgUi\Sse\SseSinkInterface;
use NaokiTsuchiya\BEARAgUi\ToolUse\InstrumentedAgentFactory;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;
use Psr\Log\LoggerInterface;

use function array_intersect;
use function array_values;

/**
 * Host-facing facade: maps an already-parsed {@see RunAgentInput} to an
 * agent run and streams the result out over SSE.
 *
 * Assembly per run (ADR 0001, architecture §5):
 *
 *  1. Pre-flight (before the sink opens): resolve the trailing user
 *     message and reconstruct the prior history. A pre-flight
 *     {@see ParseError} (empty user content) is *returned*, not streamed —
 *     the sink is never opened, so the host maps it to HTTP 400. This keeps
 *     the error dichotomy intact: connection-level failures are 400s,
 *     mid-stream failures are RUN_ERROR over an already-open 200 stream.
 *  2. Build a per-run {@see ToolCallRegistry} and hand it to the factory as
 *     the recorder, plus the seeded history (D14/D15).
 *  3. Intersect the client's declared tools with what the agent knows
 *     (D16) — empty declaration means "don't filter".
 *  4. Drive the agent → adapter → responder generator pipeline lazily.
 *
 * Lifetime: app-singleton. The factory / mapper / encoder / logger /
 * default processors are all stable; only the registry, agent, and adapter
 * are per-run (built inside {@see run()}). The sink is per-request and
 * supplied by the caller.
 *
 * @api
 */
final readonly class AgUiRunner
{
    /** @param list<InputProcessorInterface> $inputProcessors ALPS safe-only etc. (D4/ADR0004) */
    public function __construct(
        private InstrumentedAgentFactory $agentFactory,
        private MessageHistoryMapper $historyMapper,
        private SseEncoder $encoder,
        private LoggerInterface $logger,
        private array $inputProcessors,
    ) {}

    /**
     * Run the agent for `$input` and stream AG-UI events to `$sink`.
     *
     * Returns a {@see ParseError} when pre-flight validation fails (the sink
     * stays untouched so the host can respond with HTTP 400); returns `null`
     * once the stream has been driven to completion (the sink saw a 200
     * RUN_STARTED … RUN_FINISHED/RUN_ERROR sequence).
     */
    public function run(RunAgentInput $input, SseSinkInterface $sink): ParseError|null
    {
        $userMessage = $input->lastUserMessage();
        if ($userMessage instanceof ParseError) {
            return $userMessage;
        }

        $history = $this->historyMapper->map($input->historyMessages());
        $registry = new ToolCallRegistry();
        $agent = $this->agentFactory->newInstance($registry, $history);

        $options = AgentOptions::withProcessors(
            inputProcessors: $this->inputProcessors,
            enabledTools: $this->enabledTools($input->declaredToolNames()),
        );

        $agentStream = $agent->runStream($userMessage, $options);
        $adapter = new AgUiAdapter($input->threadId, $input->runId, $registry, $this->logger);
        (new SseResponder($this->encoder, $sink))->respond($adapter->run($agentStream), 200);

        return null;
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
