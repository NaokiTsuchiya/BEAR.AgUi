<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Bear;

use BEAR\ToolUse\Runtime\AlpsContextInputProcessor;
use BEAR\ToolUse\Runtime\LlmRequest;
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\ToolCollectorInterface;
use Example\Bear\ToolUris;
use Example\Bear\ToolUse\AlpsContextAsSystemPromptProcessor;
use NaokiTsuchiya\BEARAgUi\Support\ExampleBearInjectorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function str_contains;

/**
 * {@see AlpsContextInputProcessor} appends the ALPS tool/parameter semantics
 * as a plain {role: user} turn on every LLM call; models read that position
 * as a fresh user statement and reply to it instead of treating it as
 * background context. This processor moves the same text into the system
 * prompt so the conversational message list stays untouched.
 */
#[CoversClass(AlpsContextAsSystemPromptProcessor::class)]
final class AlpsContextAsSystemPromptProcessorTest extends TestCase
{
    public function testAlpsContextIsMovedIntoSystemPromptInsteadOfAUserMessage(): void
    {
        $injector = ExampleBearInjectorFactory::app();
        $dictionary = $injector->getInstance(AlpsSemanticDictionary::class);
        $tools = $injector->getInstance(ToolCollectorInterface::class)->collect(ToolUris::ALL);

        $processor = new AlpsContextAsSystemPromptProcessor(new AlpsContextInputProcessor($dictionary));
        $request = new LlmRequest('You are a helpful assistant.', [], $tools);

        $processed = $processor->process($request);

        static::assertSame([], $processed->messages);
        static::assertStringStartsWith('You are a helpful assistant.', $processed->systemPrompt);
        static::assertTrue(str_contains($processed->systemPrompt, 'Application semantics from ALPS:'));
        static::assertTrue(str_contains($processed->systemPrompt, 'weather_get'));
    }

    public function testRequestIsUnchangedWhenNoToolHasAlpsSemantics(): void
    {
        $injector = ExampleBearInjectorFactory::app();
        $dictionary = $injector->getInstance(AlpsSemanticDictionary::class);

        $processor = new AlpsContextAsSystemPromptProcessor(new AlpsContextInputProcessor($dictionary));
        $request = new LlmRequest('system', [], []);

        $processed = $processor->process($request);

        static::assertSame($request, $processed);
    }
}
