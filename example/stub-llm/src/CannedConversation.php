<?php

declare(strict_types=1);

namespace Example\StubLlm;

use stdClass;

use function end;
use function is_array;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Deterministic canned conversation in OpenAI chat.completion.chunk shape (D21).
 *
 * Detects the turn from the LAST received message's role:
 *
 *  - role !== 'tool' (turn 1): text deltas, then a `get_time` tool call with its
 *    arguments split across two chunks, ending with finish_reason "tool_calls".
 *  - role === 'tool' (turn 2): echoes the received tool result content (the real
 *    time string) inside the final text deltas, ending with finish_reason "stop".
 *
 * Pure — no I/O, no clock (the `created` timestamp is injected). The `model`
 * field mirrors the request's model for OpenAI compatibility.
 */
final readonly class CannedConversation
{
    private const CHUNK_ID = 'chatcmpl-stub-1';
    private const TOOL_CALL_ID = 'call_demo_1';
    private const TOOL_NAME = 'get_time';
    private const ARGUMENTS_CHUNK_1 = '{"timezone"';
    private const ARGUMENTS_CHUNK_2 = ':"UTC"}';

    public function __construct(
        private int $created,
    ) {}

    /**
     * @param array<string, mixed> $requestBody Decoded /v1/chat/completions request.
     *
     * @return iterable<int, array<string, mixed>> chat.completion.chunk payloads, in order
     */
    public function respond(array $requestBody): iterable
    {
        $model = is_string($requestBody['model'] ?? null) ? $requestBody['model'] : 'stub-model';
        $lastMessage = $this->lastMessage($requestBody);

        if (($lastMessage['role'] ?? null) === 'tool') {
            return $this->finalTextTurn($model, $this->toolContent($lastMessage));
        }

        return $this->toolCallTurn($model);
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    private function lastMessage(array $requestBody): array
    {
        $messages = $requestBody['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            return [];
        }

        $last = end($messages);

        return is_array($last) ? $last : [];
    }

    /**
     * Stringifies the received tool result content (OpenAI sends it as a string).
     *
     * @param array<string, mixed> $toolMessage
     */
    private function toolContent(array $toolMessage): string
    {
        $content = $toolMessage['content'] ?? '';

        return is_string($content)
            ? $content
            : json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array<string, mixed>> */
    private function toolCallTurn(string $model): array
    {
        return [
            $this->chunk($model, ['role' => 'assistant'], null),
            $this->chunk($model, ['content' => 'Let me check '], null),
            $this->chunk($model, ['content' => 'the current time.'], null),
            $this->chunk(
                $model,
                [
                    'tool_calls' => [
                        [
                            'index' => 0,
                            'id' => self::TOOL_CALL_ID,
                            'type' => 'function',
                            'function' => ['name' => self::TOOL_NAME, 'arguments' => ''],
                        ],
                    ],
                ],
                null,
            ),
            $this->chunk(
                $model,
                [
                    'tool_calls' => [
                        ['index' => 0, 'function' => ['arguments' => self::ARGUMENTS_CHUNK_1]],
                    ],
                ],
                null,
            ),
            $this->chunk(
                $model,
                [
                    'tool_calls' => [
                        ['index' => 0, 'function' => ['arguments' => self::ARGUMENTS_CHUNK_2]],
                    ],
                ],
                null,
            ),
            $this->chunk($model, [], 'tool_calls'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function finalTextTurn(string $model, string $toolContent): array
    {
        return [
            $this->chunk($model, ['role' => 'assistant'], null),
            $this->chunk($model, ['content' => 'The current time is '], null),
            $this->chunk($model, ['content' => $toolContent], null),
            $this->chunk($model, ['content' => '.'], null),
            $this->chunk($model, [], 'stop'),
        ];
    }

    /**
     * @param array<string, mixed> $delta
     *
     * @return array<string, mixed>
     */
    private function chunk(string $model, array $delta, string|null $finishReason): array
    {
        return [
            'id' => self::CHUNK_ID,
            'object' => 'chat.completion.chunk',
            'created' => $this->created,
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    // An empty delta must serialize as {} (not []) to stay byte-compatible.
                    'delta' => $delta === [] ? new stdClass() : $delta,
                    'finish_reason' => $finishReason,
                ],
            ],
        ];
    }
}
