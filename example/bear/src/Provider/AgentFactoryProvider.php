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
use RuntimeException;

use function file_get_contents;
use function trim;

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
    /**
     * Kept on the filesystem (not a PHP const) so the demo script can be
     * tuned between runs without touching code.
     */
    private const SYSTEM_PROMPT_PATH = __DIR__ . '/../../prompts/system-prompt.txt';

    public function __construct(
        private readonly StreamingLlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly ToolCollectorInterface $collector,
    ) {}

    /** @throws RuntimeException If the system prompt file cannot be read. */
    #[Override]
    public function get(): InstrumentedAgentFactory
    {
        return new ParallelStreamingAgentFactory(
            $this->client,
            $this->dispatcher,
            $this->collector->collect(ToolUris::ALL),
            $this->readSystemPrompt(),
        );
    }

    /** @throws RuntimeException If the system prompt file cannot be read. */
    private function readSystemPrompt(): string
    {
        $contents = file_get_contents(self::SYSTEM_PROMPT_PATH);
        if ($contents === false) {
            throw new RuntimeException('Unable to read system prompt file: ' . self::SYSTEM_PROMPT_PATH);
        }

        return trim($contents);
    }
}
