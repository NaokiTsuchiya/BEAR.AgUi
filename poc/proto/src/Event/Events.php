<?php

declare(strict_types=1);

namespace BEAR\AgUi\Event;

use Override;

/**
 * AG-UI event value objects.
 *
 * Field names follow the AG-UI wire format exactly (camelCase: threadId, runId,
 * messageId, toolCallId, toolCallName, delta, ...). Only the events needed for
 * the ToolUse -> AG-UI mapping are implemented here; the remaining standard
 * events (STATE_SNAPSHOT, STATE_DELTA, MESSAGES_SNAPSHOT, ...) follow the same
 * pattern and are added when their source events exist on the ToolUse side.
 */

/** Lifecycle: run started. Always the first event of a run. */
final readonly class RunStarted implements AgUiEventInterface
{
    public function __construct(
        public string $threadId,
        public string $runId,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'RUN_STARTED';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'threadId' => $this->threadId, 'runId' => $this->runId];
    }
}

/** Lifecycle: run finished successfully. Terminal event. */
final readonly class RunFinished implements AgUiEventInterface
{
    public function __construct(
        public string $threadId,
        public string $runId,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'RUN_FINISHED';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'threadId' => $this->threadId, 'runId' => $this->runId];
    }
}

/** Lifecycle: run failed during execution. Terminal event (HTTP stays 200). */
final readonly class RunError implements AgUiEventInterface
{
    public function __construct(
        public string $code,
        public string $message,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'RUN_ERROR';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'code' => $this->code, 'message' => $this->message];
    }
}

/** Text message: start of an assistant message block. Carries messageId + role. */
final readonly class TextMessageStart implements AgUiEventInterface
{
    public function __construct(
        public string $messageId,
        public string $role = 'assistant',
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TEXT_MESSAGE_START';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'messageId' => $this->messageId, 'role' => $this->role];
    }
}

/** Text message: a token/delta of the current message. */
final readonly class TextMessageContent implements AgUiEventInterface
{
    public function __construct(
        public string $messageId,
        public string $delta,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TEXT_MESSAGE_CONTENT';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'messageId' => $this->messageId, 'delta' => $this->delta];
    }
}

/** Text message: end of the current message block. */
final readonly class TextMessageEnd implements AgUiEventInterface
{
    public function __construct(
        public string $messageId,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TEXT_MESSAGE_END';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'messageId' => $this->messageId];
    }
}

/** Tool call: agent is invoking a tool. */
final readonly class ToolCallStart implements AgUiEventInterface
{
    public function __construct(
        public string $toolCallId,
        public string $toolCallName,
        public string|null $parentMessageId = null,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TOOL_CALL_START';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->type(), 'toolCallId' => $this->toolCallId, 'toolCallName' => $this->toolCallName];
        if ($this->parentMessageId !== null) {
            $data['parentMessageId'] = $this->parentMessageId;
        }

        return $data;
    }
}

/** Tool call: streamed arguments (JSON fragment) for the current tool call. */
final readonly class ToolCallArgs implements AgUiEventInterface
{
    public function __construct(
        public string $toolCallId,
        public string $delta,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TOOL_CALL_ARGS';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'toolCallId' => $this->toolCallId, 'delta' => $this->delta];
    }
}

/** Tool call: result of the tool invocation. */
final readonly class ToolCallResult implements AgUiEventInterface
{
    public function __construct(
        public string $toolCallId,
        public string $content,
        public string|null $messageId = null,
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'TOOL_CALL_RESULT';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->type(), 'toolCallId' => $this->toolCallId, 'content' => $this->content];
        if ($this->messageId !== null) {
            $data['messageId'] = $this->messageId;
        }

        return $data;
    }
}

/** Special: human-in-the-loop interrupt. Agent pauses for approval. */
final readonly class Interrupt implements AgUiEventInterface
{
    /** @param array<string, mixed> $value */
    public function __construct(
        public string $message,
        public array $value = [],
    ) {
    }

    #[Override]
    public function type(): string
    {
        return 'INTERRUPT';
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type(), 'message' => $this->message, 'value' => $this->value];
    }
}
