<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use Override;

use function is_string;

/**
 * Decorates {@see StreamingLlmClientInterface} so the adapter learns the real
 * tool-call ids the moment they arrive on the wire — early enough to emit
 * TOOL_CALL_START with the real id before arguments stream in (Tier 2).
 *
 * Pass-through: the wrapped stream is yielded verbatim so {@see
 * \BEAR\ToolUse\Runtime\StreamingAgent} keeps its own bookkeeping intact;
 * the observation just deposits id/name/input fragments into the
 * {@see ToolCallRecorder} as side-effects.
 *
 * Removable shim — see decisions.md D10.
 *
 * @api
 */
final readonly class RecordingStreamingLlmClient implements StreamingLlmClientInterface
{
    public function __construct(
        private StreamingLlmClientInterface $inner,
        private ToolCallRecorder $recorder,
    ) {
    }

    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    #[Override]
    public function chatStream(string $system, array $messages, array $tools): Generator
    {
        $stream = $this->inner->chatStream($system, $messages, $tools);
        $currentId = '';

        foreach ($stream as $event) {
            if ($event->type === StreamEvent::TOOL_USE_START) {
                $id = $this->dataString($event, 'id');
                $name = $this->dataString($event, 'name');
                $currentId = $id;
                $this->recorder->recordStart($id, $name);
            } elseif ($event->type === StreamEvent::TOOL_USE_DELTA && $currentId !== '') {
                $this->recorder->appendInput($currentId, $this->dataString($event, 'input'));
            } elseif ($event->type === StreamEvent::CONTENT_BLOCK_STOP) {
                $currentId = '';
            }

            yield $event;
        }
    }

    private function dataString(StreamEvent $event, string $key): string
    {
        $value = $event->data[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
