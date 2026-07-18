<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Bear;

use BEAR\ToolUse\Runtime\AlpsToolPolicyInputProcessor;
use BEAR\ToolUse\Runtime\LlmRequest;
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\Tool;
use BEAR\ToolUse\Schema\ToolCollectorInterface;
use Example\Bear\ToolUris;
use NaokiTsuchiya\BEARAgUi\Support\ExampleBearInjectorFactory;
use NaokiTsuchiya\BEARAgUi\ToolUse\InstrumentedAgentFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * ALPS governance wiring (tasks-m3 T4/T5, D27): the profile drives the
 * safeAndIdempotent policy — `message_post` (unsafe) disappears from the
 * LLM request while safe/idempotent tools stay — and the boot-time
 * provider hands all collected tools to the parallel agent factory.
 */
#[CoversNothing]
final class AlpsGovernanceTest extends TestCase
{
    public function testProfileClassifiesToolTransitions(): void
    {
        $dictionary = ExampleBearInjectorFactory::app()->getInstance(AlpsSemanticDictionary::class);

        static::assertSame('unsafe', $dictionary->getDescriptor('message_post')['type'] ?? null);
        static::assertSame('idempotent', $dictionary->getDescriptor('reminder_put')['type'] ?? null);
        static::assertSame('safe', $dictionary->getDescriptor('package_search')['type'] ?? null);
        static::assertSame('safe', $dictionary->getDescriptor('word_similarity_get')['type'] ?? null);
        static::assertSame('safe', $dictionary->getDescriptor('rot13_get')['type'] ?? null);
        static::assertSame('safe', $dictionary->getDescriptor('sun_info_get')['type'] ?? null);
    }

    public function testSafeAndIdempotentPolicyStripsUnsafeToolFromRequest(): void
    {
        $injector = ExampleBearInjectorFactory::app();
        $dictionary = $injector->getInstance(AlpsSemanticDictionary::class);
        $tools = $injector->getInstance(ToolCollectorInterface::class)->collect(ToolUris::ALL);

        $request = new LlmRequest('system', [], $tools);
        $filtered = AlpsToolPolicyInputProcessor::safeAndIdempotent($dictionary)->process($request);

        static::assertSame(
            [
                'reminder_put',
                'package_search',
                'word_similarity_get',
                'rot13_get',
                'sun_info_get',
            ],
            array_map(static fn(Tool $tool): string => $tool->name, $filtered->tools),
        );
    }

    public function testAgentFactoryProviderCollectsAllToolsAtBoot(): void
    {
        $factory = ExampleBearInjectorFactory::app()->getInstance(InstrumentedAgentFactory::class);

        // The provider hands every collected tool to the factory — exposure
        // is governed per request by the ALPS policy processor, not here.
        static::assertSame(
            [
                'message_post',
                'reminder_put',
                'package_search',
                'word_similarity_get',
                'rot13_get',
                'sun_info_get',
            ],
            $factory->knownToolNames(),
        );
    }
}
