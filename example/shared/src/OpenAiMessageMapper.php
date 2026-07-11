<?php

declare(strict_types=1);

namespace Example\Shared;

use BEAR\ToolUse\Runtime\Message;

/**
 * Maps bear/tool-use messages to the OpenAI chat `messages` array (D20).
 *
 * - A non-empty $system is prepended as {role: system}.
 * - bear's user role carries two payloads, told apart by content[].type:
 *   plain text blocks concatenate into one {role: user} message, while
 *   tool_result blocks expand to MULTIPLE {role: tool} messages.
 * - assistant messages emit their concatenated text (or null when there is
 *   none) plus tool_calls built from tool_use blocks.
 *
 * Block-level payload conversion lives in {@see OpenAiContentBlockMapper};
 * this class only walks messages and dispatches on their variant. The
 * branches that remain ARE that dispatch — the message-variant × block-type
 * matrix of D20 — so the defect heuristic is expected to flag it (same
 * rationale as the library's RunAgentInputParser).
 *
 * @mago-expect lint:kan-defect
 */
final readonly class OpenAiMessageMapper
{
    public function __construct(
        private OpenAiContentBlockMapper $block = new OpenAiContentBlockMapper(),
    ) {}

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

    /**
     * bear's user role doubles as the tool_result carrier (D20): a message
     * with tool_result blocks becomes those {role: tool} messages, anything
     * else concatenates its text into one {role: user} message.
     *
     * @return list<array<string, mixed>>
     */
    private function mapMessage(Message $message): array
    {
        if ($message->role === 'assistant') {
            return [$this->mapAssistant($message)];
        }

        $toolMessages = $this->mapToolResults($message);
        if ($toolMessages !== []) {
            return $toolMessages;
        }

        return [['role' => 'user', 'content' => $this->concatText($message)]];
    }

    /** @return list<array<string, mixed>> one {role: tool} message per tool_result block */
    private function mapToolResults(Message $message): array
    {
        $toolMessages = [];
        foreach ($message->content as $content) {
            if (!$this->block->isType($content, 'tool_result')) {
                continue;
            }

            $toolMessages[] = $this->block->toolResult($content);
        }

        return $toolMessages;
    }

    /** @return array<string, mixed> */
    private function mapAssistant(Message $message): array
    {
        $text = $this->concatText($message);

        $toolCalls = [];
        foreach ($message->content as $content) {
            if (!$this->block->isType($content, 'tool_use')) {
                continue;
            }

            $toolCalls[] = $this->block->toolCall($content);
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
        foreach ($message->content as $content) {
            $text .= $this->block->text($content);
        }

        return $text;
    }
}
