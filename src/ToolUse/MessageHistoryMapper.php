<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\Message as ToolUseMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;

use function array_map;
use function is_string;

/**
 * Rebuild a ToolUse {@see ToolUseMessage} history from the typed AG-UI
 * message graph.
 *
 * AG-UI keeps the conversation as the source of truth on the client and
 * ships the full transcript on every run; this mapper converts the prior
 * turns back into the shape {@see \BEAR\ToolUse\Runtime\StreamingAgent}
 * expects so it can re-enter mid-conversation (D15). The intended input is
 * the slice
 * {@see \NaokiTsuchiya\BEARAgUi\Input\RunAgentInput::historyMessages()}
 * returns — i.e. everything except the user message that triggers this run.
 *
 * A confirm-required tool call that ends its run via `interrupt` (D4) never
 * gets a {@see ToolMessage}, yet the browser resends the abandoned
 * `tool_use` turn verbatim on the next run (resume isn't supported, so the
 * turn just sits in the transcript). Anthropic/Bedrock rejects any
 * `tool_use` block that isn't immediately followed by its `tool_result`, so
 * such a turn is closed here with a synthesized cancelled result the moment
 * something other than its own {@see ToolMessage} follows it.
 *
 * Variants the mapper skips:
 *  - SystemMessage / DeveloperMessage: prompts, supplied via `systemPrompt`.
 *  - ActivityMessage / ReasoningMessage: observability metadata.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 *
 * The class CC / defect score is the price of tracking two independent
 * carry-overs across the loop (a pending tool-result batch and an
 * unresolved tool_use turn) plus the message-variant dispatch itself —
 * same rationale as {@see \Example\Shared\OpenAiMessageMapper}.
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
        /** @var list<string> $unresolvedToolUseIds */
        $unresolvedToolUseIds = [];

        foreach ($messages as $message) {
            // Tool messages batch together: keep appending until any non-tool
            // message draws the boundary that flushes the batch below.
            if ($message instanceof ToolMessage) {
                $pendingToolResults[] = $this->toToolResult($message);

                continue;
            }

            $flushed = $this->flushBoundary($pendingToolResults, $unresolvedToolUseIds);
            if ($flushed !== null) {
                $history[] = $flushed;
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
                $unresolvedToolUseIds = $message instanceof AssistantMessage ? $this->toolUseIds($message) : [];
            }
        }

        $flushed = $this->flushBoundary($pendingToolResults, $unresolvedToolUseIds);
        if ($flushed !== null) {
            $history[] = $flushed;
        }

        return $history;
    }

    /**
     * Closes whichever batch is open — resolved tool results take priority
     * over a still-unresolved `tool_use` turn, since a `pendingToolResults`
     * batch can only exist when its own assistant turn already cleared
     * `$unresolvedToolUseIds` (see {@see map()}).
     *
     * @param list<ToolResult> $pendingToolResults
     * @param list<string>     $unresolvedToolUseIds
     */
    private function flushBoundary(array &$pendingToolResults, array &$unresolvedToolUseIds): ToolUseMessage|null
    {
        if ($pendingToolResults !== []) {
            $flushed = ToolUseMessage::toolResults($pendingToolResults);
            $pendingToolResults = [];
            $unresolvedToolUseIds = [];

            return $flushed;
        }

        if ($unresolvedToolUseIds !== []) {
            $flushed = ToolUseMessage::toolResults(array_map(ToolResult::cancelled(...), $unresolvedToolUseIds));
            $unresolvedToolUseIds = [];

            return $flushed;
        }

        return null;
    }

    /** @return list<string> */
    private function toolUseIds(AssistantMessage $message): array
    {
        $ids = [];
        foreach ($message->toolCalls as $call) {
            $ids[] = $call->id;
        }

        return $ids;
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
        $isError = $outcome->isError();
        if ($isError) {
            // `error` is non-null when isError() is true; cast for the analyzer.
            $errorText = (string) $outcome->error;
            $fallback = $errorText === '' && is_string($outcome->content) ? $outcome->content : $errorText;

            return ToolResult::error($message->toolCallId, $fallback);
        }

        return ToolResult::success($message->toolCallId, $outcome->content);
    }
}
