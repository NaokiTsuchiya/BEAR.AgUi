<?php

declare(strict_types=1);

namespace Example\Bear\Module;

use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use Example\Bear\Provider\AgentFactoryProvider;
use Example\Bear\Provider\AgUiRunnerProvider;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInputParser;
use NaokiTsuchiya\BEARAgUi\Sse\SseEncoder;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use NaokiTsuchiya\BEARAgUi\ToolUse\InstrumentedAgentFactory;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use ReflectionException;

use function dirname;

/**
 * AG-UI wiring (tasks-m3 T8, architecture §2): every collaborator is an
 * app-singleton — the per-run ToolCallRegistry / agent are created inside
 * AgUiRunner::stream(), never DI-scoped. SSE delivery is not bound here:
 * Invocations::transfer() builds the per-request SwooleSseSink from the
 * responder the server hands it (D25/D29).
 */
final class AgUiModule extends AbstractModule
{
    /** @throws ReflectionException From the toConstructor reflection — a boot-time misconfiguration, not a runtime path. */
    #[Override]
    protected function configure(): void
    {
        $appDir = dirname(__DIR__, 2);

        $this
            ->bind()
            ->annotatedWith('alps_profile_path')
            ->toInstance($appDir . '/alps/profile.xml');
        $this->bind(AlpsSemanticDictionary::class)->toConstructor(AlpsSemanticDictionary::class, [
            'profilePath' => 'alps_profile_path',
        ])->in(Scope::SINGLETON);

        $this->bind(LoggerInterface::class)->to(NullLogger::class)->in(Scope::SINGLETON);
        $this->bind(RunAgentInputParser::class)->in(Scope::SINGLETON);
        $this->bind(SseEncoder::class)->in(Scope::SINGLETON);
        $this->bind(SseResponder::class)->in(Scope::SINGLETON);
        $this->bind(MessageHistoryMapper::class)->in(Scope::SINGLETON);
        $this->bind(AgUiAdapter::class)->in(Scope::SINGLETON);

        $this->bind(InstrumentedAgentFactory::class)->toProvider(AgentFactoryProvider::class)->in(Scope::SINGLETON);
        $this->bind(AgUiRunner::class)->toProvider(AgUiRunnerProvider::class)->in(Scope::SINGLETON);
    }
}
