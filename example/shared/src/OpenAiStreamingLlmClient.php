<?php

declare(strict_types=1);

namespace Example\Shared;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use OpenAI\Contracts\ClientContract;
use Override;
use RuntimeException;

/**
 * Streams OpenAI chat completions as bear/tool-use StreamEvents (D19).
 *
 * Write side maps system/messages/tools through the D20 mappers; read side is
 * a small state machine over chat.completion.chunk deltas that tracks the open
 * content block (none | text | tool) and inserts CONTENT_BLOCK_STOP at every
 * block boundary:
 *
 *  - non-empty delta.content        -> TEXT_DELTA (closing an open tool block first)
 *  - tool_calls[].id (first chunk)  -> TOOL_USE_START (closing any open block first)
 *  - tool_calls[].function.arguments-> TOOL_USE_DELTA carrying the raw JSON fragment
 *  - finish_reason                  -> close the open block, then MESSAGE_STOP with
 *    tool_calls|function_call mapped to "tool_use" and everything else
 *    (stop, length, content_filter, ...) mapped to "end_turn".
 *
 * Sequential tool calls only: argument fragments interleaved across different
 * tool_calls indexes are not supported. bear's StreamContentAccumulator keeps
 * a single current tool block anyway, and OpenAI streams tool calls
 * sequentially in practice.
 *
 * A stream that ends without any finish_reason chunk (truncated SSE) throws,
 * so the run surfaces as RUN_ERROR instead of a fabricated end_turn (D23).
 */
final readonly class OpenAiStreamingLlmClient implements StreamingLlmClientInterface
{
    private const OPEN_NONE = 'none';
    private const OPEN_TEXT = 'text';
    private const OPEN_TOOL = 'tool';

    public function __construct(
        private ClientContract $client,
        private OpenAiMessageMapper $messageMapper,
        private OpenAiToolMapper $toolMapper,
        private string $model,
    ) {}

    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    #[Override]
    public function chatStream(string $system, array $messages, array $tools): Generator
    {
        $parameters = [
            'model' => $this->model,
            'messages' => $this->messageMapper->map($system, $messages),
        ];
        if ($tools !== []) {
            $parameters['tools'] = $this->toolMapper->map($tools);
        }

        $open = self::OPEN_NONE;
        $finished = false;
        foreach ($this->client->chat()->createStreamed($parameters) as $chunk) {
            $choice = $chunk->choices[0] ?? null;
            if ($choice === null) {
                continue;
            }

            $content = $choice->delta->content;
            if ($content !== null && $content !== '') {
                if ($open === self::OPEN_TOOL) {
                    yield new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP);
                }

                yield new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => $content]);

                $open = self::OPEN_TEXT;
            }

            foreach ($choice->delta->toolCalls as $toolCall) {
                if ($toolCall->id !== null && $toolCall->id !== '') {
                    if ($open !== self::OPEN_NONE) {
                        yield new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP);
                    }

                    yield new StreamEvent(StreamEvent::TOOL_USE_START, [
                        'id' => $toolCall->id,
                        'name' => (string) $toolCall->function->name,
                    ]);

                    $open = self::OPEN_TOOL;
                }

                if ($toolCall->function->arguments !== '') {
                    yield new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolCall->function->arguments]);
                }
            }

            if ($choice->finishReason === null) {
                continue;
            }

            if ($open !== self::OPEN_NONE) {
                yield new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP);

                $open = self::OPEN_NONE;
            }

            yield new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => self::stopReason($choice->finishReason)]);

            $finished = true;
        }

        if (!$finished) {
            throw new RuntimeException('LLM stream ended without finish_reason');
        }
    }

    /** tool_calls/function_call keep the agent loop running; everything else is terminal (D19). */
    private static function stopReason(string $finishReason): string
    {
        return match ($finishReason) {
            'tool_calls', 'function_call' => 'tool_use',
            default => 'end_turn',
        };
    }
}
