<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Runtime\AgentEvent;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use PHPUnit\Framework\Assert;

use function array_map;
use function array_values;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Scenario builders and generator-driving helpers shared by the parallel
 * agent tests: scripted LLM turns, Tool definitions, and consumption with
 * confirmation answers.
 */
trait ParallelAgentScenarioFixture
{
    /**
     * Drive the generator, answering every CONFIRMATION_REQUIRED with
     * `$approval` (Generator::send), like a host consuming the stream.
     *
     * @param Generator<int, AgentEvent, mixed, void> $stream
     *
     * @return list<AgentEvent>
     */
    private function drive(Generator $stream, bool $approval): array
    {
        $events = [];
        while ($stream->valid()) {
            $event = $stream->current();
            Assert::assertInstanceOf(AgentEvent::class, $event);
            $events[] = $event;
            if ($event->type === AgentEvent::CONFIRMATION_REQUIRED) {
                $stream->send($approval);
                continue;
            }

            $stream->next();
        }

        return $events;
    }

    /**
     * @param Generator<int, AgentEvent, mixed, void> $stream
     *
     * @return list<string>
     */
    private function encodeAll(Generator $stream): array
    {
        $encoded = [];
        foreach ($stream as $event) {
            $encoded[] = json_encode($event, JSON_THROW_ON_ERROR);
        }

        return $encoded;
    }

    /**
     * One LLM turn requesting the given tool calls: leading text, then one
     * tool_use block per [id, name, argumentsJson] triple.
     *
     * @param list<array{0: string, 1: string, 2: string}> $calls
     *
     * @return list<StreamEvent>
     */
    private function toolTurn(array $calls): array
    {
        $events = [
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'working…']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
        ];
        foreach ($calls as [$id, $name, $argumentsJson]) {
            $events[] = new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => $id, 'name' => $name]);
            $events[] = new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $argumentsJson]);
            $events[] = new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP);
        }

        $events[] = new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']);

        return array_values($events);
    }

    /** @return list<StreamEvent> */
    private function finalTurn(string $text): array
    {
        return [
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => $text]),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ];
    }

    /**
     * @param list<AgentEvent> $events
     *
     * @return list<string>
     */
    private function typesOf(array $events): array
    {
        return array_map(static fn(AgentEvent $e): string => $e->type, $events);
    }

    /**
     * @param list<AgentEvent> $events
     *
     * @return list<mixed>
     */
    private function toolResultNames(array $events): array
    {
        $names = [];
        foreach ($events as $event) {
            if ($event->type !== AgentEvent::TOOL_RESULT) {
                continue;
            }

            $names[] = $event->data['toolName'] ?? null;
        }

        return $names;
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, '', ['type' => 'object', 'properties' => [], 'required' => []]);
    }

    private function confirmableTool(string $name): Tool
    {
        return new Tool($name, '', ['type' => 'object', 'properties' => [], 'required' => []], true);
    }
}
