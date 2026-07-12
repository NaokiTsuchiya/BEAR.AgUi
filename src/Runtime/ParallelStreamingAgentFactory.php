<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\OptionAwareStreamingAgentInterface;
use BEAR\ToolUse\Schema\Tool;
use NaokiTsuchiya\BEARAgUi\ToolUse\InstrumentedAgentFactory;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingDispatcher;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRecorder;
use Override;

use function array_map;

/**
 * {@see InstrumentedAgentFactory} that builds a {@see ParallelStreamingAgent}
 * instead of the sequential StreamingAgent — a drop-in replacement for
 * {@see \NaokiTsuchiya\BEARAgUi\ToolUse\StreamingAgentFactory} when the host
 * runs on Swoole and wants one turn's plain tool calls dispatched
 * concurrently (D29). AgUiRunner takes it unchanged.
 *
 * Per run it wraps the app-singleton LLM client / dispatcher with the
 * recording decorators (D10) so the ToolCallRegistry receives the
 * id / input / content side-channel, and seeds the conversation
 * history (D15).
 *
 * @api
 */
final readonly class ParallelStreamingAgentFactory implements InstrumentedAgentFactory
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
        $agent = new ParallelStreamingAgent(
            new RecordingStreamingLlmClient($this->client, $recorder),
            new RecordingDispatcher($this->dispatcher, $recorder),
            $this->tools,
            $this->systemPrompt,
        );

        // Public property, same seam as the sequential factory (D15).
        $agent->messages = $history;

        return $agent;
    }

    #[Override]
    public function knownToolNames(): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, $this->tools);
    }
}
