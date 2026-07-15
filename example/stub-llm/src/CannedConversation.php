<?php

declare(strict_types=1);

namespace Example\StubLlm;

use stdClass;

use function is_string;

/**
 * Deterministic canned conversations in OpenAI chat.completion.chunk shape (D21).
 *
 * Detects the turn from the LAST received message's role — role 'tool'
 * means turn 2 (echo the received tool results in the final text, ending
 * with finish_reason "stop"), anything else is turn 1 (text deltas plus
 * tool calls with arguments split across chunks, ending "tool_calls").
 *
 * Which conversation is played is {@see StubScenario}'s selection rule —
 * keyed off the newest human user message ("remind" → confirm demo,
 * "weather"/"news" → parallel demo, default get_time).
 *
 * Pure — no I/O, no clock (the `created` timestamp is injected). The `model`
 * field mirrors the request's model for OpenAI compatibility.
 */
final readonly class CannedConversation
{
    private const CHUNK_ID = 'chatcmpl-stub-1';

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
        $messages = StubRequest::messages($requestBody);
        $scenario = StubScenario::detect($messages);

        $isToolTurn = StubRequest::isToolTurn($messages);
        if ($isToolTurn) {
            return $this->finalTextTurn($model, $scenario, StubRequest::trailingToolContent($messages));
        }

        return $this->toolCallTurn($model, $scenario);
    }

    /** @return list<array<string, mixed>> */
    private function toolCallTurn(string $model, string $scenario): array
    {
        $chunks = [$this->chunk($model, ['role' => 'assistant'], null)];
        foreach (StubScenario::LEAD_TEXT[$scenario] ?? [] as $text) {
            $chunks[] = $this->chunk($model, ['content' => $text], null);
        }

        foreach (StubScenario::TOOL_CALLS[$scenario] ?? [] as $index => [$id, $name, $argumentChunks]) {
            $chunks[] = $this->chunk(
                $model,
                [
                    'tool_calls' => [
                        [
                            'index' => $index,
                            'id' => $id,
                            'type' => 'function',
                            'function' => ['name' => $name, 'arguments' => ''],
                        ],
                    ],
                ],
                null,
            );
            foreach ($argumentChunks as $argumentChunk) {
                $chunks[] = $this->chunk(
                    $model,
                    [
                        'tool_calls' => [
                            ['index' => $index, 'function' => ['arguments' => $argumentChunk]],
                        ],
                    ],
                    null,
                );
            }
        }

        $chunks[] = $this->chunk($model, [], 'tool_calls');

        return $chunks;
    }

    /** @return list<array<string, mixed>> */
    private function finalTextTurn(string $model, string $scenario, string $toolContent): array
    {
        $finalPrefix = StubScenario::FINAL_PREFIX[$scenario] ?? '';

        return [
            $this->chunk($model, ['role' => 'assistant'], null),
            $this->chunk($model, ['content' => $finalPrefix], null),
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
