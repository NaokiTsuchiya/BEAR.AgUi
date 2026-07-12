<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\AgentEvent;
use BEAR\ToolUse\Runtime\AgentOptions;
use BEAR\ToolUse\Runtime\LlmRequest;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Runtime\OptionAwareStreamingAgentInterface;
use BEAR\ToolUse\Runtime\StreamContentAccumulator;
use BEAR\ToolUse\Runtime\StreamIterationState;
use BEAR\ToolUse\Runtime\ToolList;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use Override;

/**
 * Streaming agent that executes plain tool calls of one turn concurrently
 * on Swoole coroutines (D29).
 *
 * The reason/act loop mirrors {@see \BEAR\ToolUse\Runtime\StreamingAgent}
 * (which is final and therefore cannot be extended); every other building
 * block — {@see StreamContentAccumulator}, {@see StreamIterationState},
 * {@see ToolList}, {@see Message}, {@see LlmRequest}, {@see AgentOptions},
 * {@see AgentEvent} — is reused from bear/tool-use. The only new logic is
 * the dispatch fan-out in {@see ParallelToolDispatch}: confirmations are
 * resolved serially (approved ones execute before the parallel wave — a
 * human just approved that exact action, so it runs deterministically),
 * plain calls fan out on a WaitGroup, and `tool_result` events are yielded
 * in pending order. With no confirmable tools in a turn the produced
 * AgentEvent sequence is identical to the sequential StreamingAgent's.
 *
 * Requires a Swoole coroutine context (e.g. a request handled by
 * `Swoole\Http\Server` or a `Swoole\Coroutine\run()` scope); without one the
 * WaitGroup wait fails. The sequential StreamingAgent remains the default —
 * this runtime is opt-in via {@see ParallelStreamingAgentFactory}.
 *
 * @api
 */
final class ParallelStreamingAgent implements OptionAwareStreamingAgentInterface
{
    /** @var list<Message> */
    public array $messages = [];

    private readonly ParallelToolDispatch $toolDispatch;

    /** @param list<Tool> $tools */
    public function __construct(
        private readonly StreamingLlmClientInterface $client,
        DispatcherInterface $dispatcher,
        private readonly array $tools,
        private readonly string $systemPrompt,
        private readonly int $maxIterations = 10,
    ) {
        $this->toolDispatch = new ParallelToolDispatch($dispatcher);
    }

    /** @return Generator<int, AgentEvent, mixed, void> */
    #[Override]
    public function runStream(string $userMessage, AgentOptions|null $options = null): Generator
    {
        $runTools = $options?->filterTools($this->tools) ?? $this->tools;

        $this->messages[] = Message::user($userMessage);
        $fullText = '';
        $hadPreviousText = false;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $request = $this->createRequest($runTools, $options);
            $stream = $this->processStream(
                $this->client->chatStream(
                    system: $request->systemPrompt,
                    messages: $request->messages,
                    tools: $request->tools,
                ),
                $request,
                $options,
            );
            $requestToolList = new ToolList($request->tools);

            $consumeGen = $this->consumeStream($stream, $hadPreviousText, $fullText);
            foreach ($consumeGen as $event) {
                yield $event;
            }

            $state = $consumeGen->getReturn();
            $fullText = $state->fullText;

            if ($state->stopReason !== 'tool_use' || $state->pendingToolCalls === []) {
                $this->recordContentBlocks($state);

                yield AgentEvent::completed($fullText);

                return;
            }

            $this->messages[] = Message::assistant($state->contentBlocks);

            $dispatchGen = $this->toolDispatch->run($state->pendingToolCalls, $state->currentText, $requestToolList);
            while ($dispatchGen->valid()) {
                /** @var AgentEvent $confirmationOrResult */
                $confirmationOrResult = $dispatchGen->current();
                /** @psalm-suppress MixedAssignment */
                $sent = yield $confirmationOrResult;
                $dispatchGen->send($sent === true);
            }

            $this->messages[] = Message::toolResults($dispatchGen->getReturn());
            if ($state->currentText !== '') {
                $hadPreviousText = true;
            }
        }

        yield AgentEvent::error('Max iterations reached');
    }

    #[Override]
    public function reset(): void
    {
        $this->messages = [];
    }

    private function recordContentBlocks(StreamIterationState $state): void
    {
        if ($state->contentBlocks === []) {
            return;
        }

        $this->messages[] = Message::assistant($state->contentBlocks);
    }

    /** @param list<Tool> $tools */
    private function createRequest(array $tools, AgentOptions|null $options): LlmRequest
    {
        $request = new LlmRequest($this->systemPrompt, $this->messages, $tools);

        return $options?->processRequest($request) ?? $request;
    }

    /**
     * @param Generator<int, StreamEvent, mixed, void> $stream
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    private function processStream(Generator $stream, LlmRequest $request, AgentOptions|null $options): Generator
    {
        foreach ($stream as $event) {
            yield $options?->processStreamEvent($event, $request) ?? $event;
        }
    }

    /**
     * @param Generator<int, StreamEvent, mixed, void> $stream
     *
     * @return Generator<int, AgentEvent, mixed, StreamIterationState>
     */
    private function consumeStream(Generator $stream, bool $hadPreviousText, string $fullText): Generator
    {
        $accumulator = new StreamContentAccumulator($hadPreviousText, $fullText);

        foreach ($stream as $event) {
            foreach ($accumulator->handleEvent($event) as $agentEvent) {
                yield $agentEvent;
            }
        }

        return $accumulator->toState();
    }
}
