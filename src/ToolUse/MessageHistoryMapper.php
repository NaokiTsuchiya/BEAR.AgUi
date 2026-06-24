<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\Message as ToolUseMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;

use function is_string;

/**
 * Rebuild a ToolUse {@see ToolUseMessage} history from the typed AG-UI
 * message graph.
 *
 * AG-UI keeps the conversation as the source of truth on the client and
 * ships the full transcript on every run; this mapper converts the prior
 * turns back into the shape {@see \BEAR\ToolUse\Runtime\StreamingAgent}
 * expects so it can re-enter mid-conversation (D15). Reconstruction is
 * *all-or-nothing per turn*: an assistant `tool_use` block without its
 * matching tool_result would leave the ReAct loop with an unresolved call,
 * so partial histories are intentionally not supported. The intended input
 * is the slice
 * {@see \NaokiTsuchiya\BEARAgUi\Input\RunAgentInput::historyMessages()}
 * returns — i.e. everything except the user message that triggers this run.
 *
 * Variants the mapper skips:
 *  - SystemMessage / DeveloperMessage: prompts, supplied via `systemPrompt`.
 *  - ActivityMessage / ReasoningMessage: observability metadata.
 *
 * @internal
 */
final class MessageHistoryMapper
{
    /**
     * @param list<Message> $messages
     *
     * @return list<ToolUseMessage>
     */
    public function map(array $messages): array
    {
        $history = [];
        /** @var list<ToolResult> $pendingToolResults */
        $pendingToolResults = [];

        foreach ($messages as $message) {
            // Tool messages batch together: keep appending until any non-tool
            // message draws the boundary that flushes the batch below.
            if ($message instanceof ToolMessage) {
                $pendingToolResults[] = $this->toToolResult($message);

                continue;
            }

            if ($pendingToolResults !== []) {
                $history[] = ToolUseMessage::toolResults($pendingToolResults);
                $pendingToolResults = [];
            }

            // System / Developer / Activity / Reasoning fall through the
            // match's `default` and are intentionally skipped.
            $converted = match (true) {
                $message instanceof UserMessage => ToolUseMessage::user($message->text),
                $message instanceof AssistantMessage => $this->toAssistant($message),
                default => null,
            };
            if ($converted !== null) {
                $history[] = $converted;
            }
        }

        if ($pendingToolResults !== []) {
            $history[] = ToolUseMessage::toolResults($pendingToolResults);
        }

        return $history;
    }

    private function toAssistant(AssistantMessage $message): ToolUseMessage|null
    {
        $blocks = [];

        if (is_string($message->content) && $message->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message->content];
        }

        foreach ($message->toolCalls as $call) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $call->id,
                'name' => $call->name,
                'input' => $call->arguments,
            ];
        }

        if ($blocks === []) {
            return null;
        }

        return ToolUseMessage::assistant($blocks);
    }

    private function toToolResult(ToolMessage $message): ToolResult
    {
        $outcome = $message->outcome;
        if ($outcome->isError()) {
            // `error` is non-null when isError() is true; cast for the analyzer.
            $errorText = (string) $outcome->error;
            $fallback = $errorText === '' && is_string($outcome->content) ? $outcome->content : $errorText;

            return ToolResult::error($message->toolCallId, $fallback);
        }

        return ToolResult::success($message->toolCallId, $outcome->content);
    }
}
