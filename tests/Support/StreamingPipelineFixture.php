<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use BEAR\ToolUse\Runtime\StreamingAgent;
use BEAR\ToolUse\Schema\Tool;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Fake\FakeDispatcher;
use NaokiTsuchiya\BEARAgUi\Fake\FakeStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingDispatcher;
use NaokiTsuchiya\BEARAgUi\ToolUse\RecordingStreamingLlmClient;
use NaokiTsuchiya\BEARAgUi\ToolUse\ToolCallRegistry;

/**
 * Shared helpers for end-to-end pipeline tests that drive a real
 * StreamingAgent through the recording decorators and adapter. Keeps the
 * test classes themselves focused on scenario assertions.
 */
trait StreamingPipelineFixture
{
    /**
     * @param list<Tool> $tools
     *
     * @return array{0: list<AgUiEventInterface>}
     */
    private function runPipeline(
        FakeStreamingLlmClient $llm,
        FakeDispatcher $dispatcher,
        array $tools,
        string $userMessage,
    ): array {
        $registry = new ToolCallRegistry();
        $agent = new StreamingAgent(
            new RecordingStreamingLlmClient($llm, $registry),
            new RecordingDispatcher($dispatcher, $registry),
            tools: $tools,
            systemPrompt: '',
        );
        $adapter = new AgUiAdapter('t', 'r', $registry, null);

        $events = [];
        foreach ($adapter->run($agent->runStream($userMessage)) as $event) {
            $events[] = $event;
        }

        return [$events];
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, '', ['type' => 'object', 'properties' => [], 'required' => []], false);
    }

    private function confirmableTool(string $name): Tool
    {
        return new Tool($name, '', ['type' => 'object', 'properties' => [], 'required' => []], true);
    }

    /**
     * @param list<AgUiEventInterface> $events
     *
     * @return list<class-string>
     */
    private function types(array $events): array
    {
        return array_map(static fn($event) => $event::class, $events);
    }

    /**
     * @param list<AgUiEventInterface> $events
     * @param class-string             $class
     */
    private function firstOf(array $events, string $class): AgUiEventInterface|null
    {
        foreach ($events as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        return null;
    }
}
