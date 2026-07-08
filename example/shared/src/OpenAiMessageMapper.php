<?php

declare(strict_types=1);

namespace Example\Shared;

use BEAR\ToolUse\Runtime\Message;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Maps bear/tool-use messages to the OpenAI chat `messages` array (D20).
 *
 * - A non-empty $system is prepended as {role: system}.
 * - bear's user role carries two payloads, told apart by content[].type:
 *   plain text blocks concatenate into one {role: user} message, while
 *   tool_result blocks expand to MULTIPLE {role: tool, tool_call_id, content}
 *   messages (OpenAI wants one tool message per call; content must be a
 *   string, so non-string results are json_encoded).
 * - assistant messages emit their concatenated text (or null when there is
 *   none) plus tool_calls built from tool_use blocks, with the input
 *   re-encoded as a JSON string.
 */
final readonly class OpenAiMessageMapper
{
    /**
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>> OpenAI `messages` request entries
     */
    public function map(string $system, array $messages): array
    {
        $mapped = [];
        if ($system !== '') {
            $mapped[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $message) {
            foreach ($this->mapMessage($message) as $openAiMessage) {
                $mapped[] = $openAiMessage;
            }
        }

        return $mapped;
    }

    /** @return list<array<string, mixed>> */
    private function mapMessage(Message $message): array
    {
        if ($message->role === 'assistant') {
            return [$this->mapAssistant($message)];
        }

        if ($this->hasToolResults($message)) {
            return $this->mapToolResults($message);
        }

        return [['role' => 'user', 'content' => $this->concatText($message)]];
    }

    /** bear's user role doubles as the tool_result carrier; content[].type tells them apart (D20). */
    private function hasToolResults(Message $message): bool
    {
        foreach ($message->content as $block) {
            if (($block['type'] ?? null) === 'tool_result') {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> one {role: tool} message per tool_result block */
    private function mapToolResults(Message $message): array
    {
        $toolMessages = [];
        foreach ($message->content as $block) {
            if (($block['type'] ?? null) !== 'tool_result') {
                continue;
            }

            $toolMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $block['tool_use_id'] ?? '',
                'content' => $this->stringify($block['content'] ?? ''),
            ];
        }

        return $toolMessages;
    }

    /** @return array<string, mixed> */
    private function mapAssistant(Message $message): array
    {
        $text = $this->concatText($message);

        $toolCalls = [];
        foreach ($message->content as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $toolCalls[] = [
                'id' => $block['id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $block['name'] ?? '',
                    'arguments' => $this->encodeArguments($block['input'] ?? []),
                ],
            ];
        }

        $assistant = ['role' => 'assistant', 'content' => $text === '' ? null : $text];
        if ($toolCalls !== []) {
            $assistant['tool_calls'] = $toolCalls;
        }

        return $assistant;
    }

    private function concatText(Message $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /** OpenAI tool message content must be a string; non-strings are json_encoded. */
    private function stringify(mixed $content): string
    {
        return is_string($content) ? $content : $this->encode($content);
    }

    /** Tool-call arguments are a JSON object string; an empty input encodes as {} (not []). */
    private function encodeArguments(mixed $input): string
    {
        return $input === [] ? '{}' : $this->encode($input);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
