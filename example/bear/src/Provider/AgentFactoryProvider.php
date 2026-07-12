<?php

declare(strict_types=1);

namespace Example\Bear\Provider;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Schema\ToolCollectorInterface;
use Example\Bear\ToolUris;
use NaokiTsuchiya\BEARAgUi\Runtime\ParallelStreamingAgentFactory;
use NaokiTsuchiya\BEARAgUi\ToolUse\InstrumentedAgentFactory;
use Override;
use Ray\Di\ProviderInterface;

/**
 * Builds the app-single agent factory (tasks-m3 T4, D26/D29): collects the
 * tool declarations from the #[Tool] resources once at boot — the side
 * effect fills bear/tool-use's ToolRegistry, which the resource-driven
 * Dispatcher reads — and hands the raw client/dispatcher to a
 * ParallelStreamingAgentFactory. The recording decorators are wired
 * per-run inside the factory (S5), not here.
 *
 * @implements ProviderInterface<InstrumentedAgentFactory>
 */
final class AgentFactoryProvider implements ProviderInterface
{
    /** Minimal on purpose: enough to make a real model use the tools; the stub ignores it. */
    private const SYSTEM_PROMPT = 'You are a helpful assistant. Use the provided tools when relevant.';

    public function __construct(
        private readonly StreamingLlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly ToolCollectorInterface $collector,
    ) {}

    #[Override]
    public function get(): InstrumentedAgentFactory
    {
        return new ParallelStreamingAgentFactory(
            $this->client,
            $this->dispatcher,
            $this->collector->collect(ToolUris::ALL),
            self::SYSTEM_PROMPT,
        );
    }
}
