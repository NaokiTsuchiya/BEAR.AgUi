<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\OptionAwareStreamingAgentInterface;
use BEAR\ToolUse\Runtime\StreamingAgent;
use BEAR\ToolUse\Schema\Tool;
use Override;

use function array_map;

/**
 * Bundled {@see InstrumentedAgentFactory} for hosts that build the agent
 * directly from a single LLM client / dispatcher pair (no AgentPool, no
 * AgentFactory chain).
 *
 * Wraps the real LLM client with {@see RecordingStreamingLlmClient} and
 * the real dispatcher with {@see RecordingDispatcher} so the
 * {@see ToolCallRegistry} receives id / input / content / isError
 * side-channel data the high-level `AgentEvent` stream drops (D10),
 * constructs a fresh {@see StreamingAgent}, and seeds `$messages` with
 * the prior conversation (D15).
 *
 * Lifetime: app-singleton — the real LLM client/dispatcher/tools/system
 * prompt are stable; only the per-run decorators are built afresh by
 * {@see newInstance()}.
 *
 * @api
 */
final readonly class StreamingAgentFactory implements InstrumentedAgentFactory
{
    /** @param list<Tool> $tools */
    public function __construct(
        private StreamingLlmClientInterface $client,
        private DispatcherInterface $dispatcher,
        private array $tools,
        private string $systemPrompt,
    ) {}

    #[Override]
    public function newInstance(ToolCallRecorder $recorder, array $history): OptionAwareStreamingAgentInterface
    {
        $agent = new StreamingAgent(
            new RecordingStreamingLlmClient($this->client, $recorder),
            new RecordingDispatcher($this->dispatcher, $recorder),
            $this->tools,
            $this->systemPrompt,
        );

        // Public property on StreamingAgent (D15: until ToolUse exposes a
        // formal history-seed API, this is the documented seam — see
        // feedback/tool-use-resume.md).
        $agent->messages = $history;

        return $agent;
    }

    #[Override]
    public function knownToolNames(): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, $this->tools);
    }
}
