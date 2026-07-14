<?php

declare(strict_types=1);

namespace Example\Bear\Module;

use BEAR\Resource\Module\ResourceModule;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Module\ToolUseModule;
use Example\Bear\Provider\LlmClientProvider;
use GuzzleHttp\Client;
use Override;
use Psr\Http\Client\ClientInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

use function dirname;

/**
 * Composition root of the BEAR showcase app (tasks-m3 T8):
 * BEAR.Resource + bear/tool-use (resource-driven Dispatcher / ToolRegistry
 * / ToolCollector as-is, D26) + the AG-UI wiring, plus the real LLM client
 * bound from environment variables (OPENAI_BASE_URL switches stub/real).
 */
final class AppModule extends AbstractModule
{
    #[Override]
    protected function configure(): void
    {
        $this->install(new ResourceModule('Example\Bear'));
        $this->install(new ToolUseModule());
        $this->install(new AgUiModule());

        // ToolUseModule's JsonSchemaRepository wants a schema dir; the demo
        // resources carry no #[JsonSchema], so an empty directory suffices.
        $this
            ->bind()
            ->annotatedWith('json_validate_dir')
            ->toInstance(dirname(__DIR__, 2) . '/var/schema');

        $this->bind(StreamingLlmClientInterface::class)->toProvider(LlmClientProvider::class)->in(Scope::SINGLETON);

        // Real (non-canned) HTTP for package_search (Package.php) — swapped
        // for a fake in tests so they never touch the network (D22 pattern).
        $this->bind(ClientInterface::class)->toInstance(new Client(['timeout' => 5.0]));
    }
}
