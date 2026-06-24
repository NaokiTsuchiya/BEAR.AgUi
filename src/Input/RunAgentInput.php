<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;

use function array_map;
use function array_reverse;
use function array_slice;

/**
 * AG-UI RunAgentInput as a typed value object.
 *
 * Wire shape (per AG-UI spec):
 *   { threadId, runId, messages[], tools[], context[], state, forwardedProps,
 *     resume?[] }
 *
 * The endpoint that hosts the adapter passes the POST body unvalidated, so
 * validation is the library's responsibility and happens here, before the
 * stream starts. A failure here is a connection-level error (HTTP 400) —
 * distinct from a RUN_ERROR mid-stream.
 *
 * Build instances via {@see RunAgentInputParser::parse()} — the parser is
 * the single place that converts the AG-UI wire arrays into the typed VO
 * graph this class exposes. Consumers iterate over `list<Message>` /
 * `list<Tool>` / `list<Context>` / `list<Resume>` instead of poking at
 * untyped arrays. `state` and `forwardedProps` stay as associative arrays
 * because they are intentionally free-form per the spec.
 *
 * @api
 */
final readonly class RunAgentInput
{
    /**
     * @param list<Message>             $messages
     * @param list<Tool>                $tools
     * @param list<Context>             $context
     * @param array<string, mixed>|null $state
     * @param array<string, mixed>      $forwardedProps
     * @param list<Resume>              $resume
     *
     * @mago-expect lint:excessive-parameter-list
     *
     * The constructor mirrors the AG-UI RunAgentInput wire schema
     * (threadId, runId, messages, tools, context, state, forwardedProps,
     * resume); splitting it into sub-DTOs would drift from the spec.
     */
    public function __construct(
        public string $threadId,
        public string $runId,
        public array $messages,
        public array $tools,
        public array $context,
        public array|null $state,
        public array $forwardedProps,
        public array $resume,
    ) {}

    /**
     * Text of the last user message — what ToolUse runStream() takes.
     *
     * Walks `messages[]` back-to-front, stops at the first {@see UserMessage}
     * and projects its content via {@see UserMessage::text()} (multimodal
     * parts are dropped per D17). Returns a {@see ParseError} when no user
     * message exists or its extracted text is empty — callers translate that
     * to HTTP 400 (ADR 0001) before opening the stream.
     */
    public function lastUserMessage(): string|ParseError
    {
        foreach (array_reverse($this->messages) as $message) {
            if (!$message instanceof UserMessage) {
                continue;
            }

            if ($message->text === '') {
                return new ParseError('User message has no text content.');
            }

            return $message->text;
        }

        return new ParseError('No user message found in messages[].');
    }

    /**
     * Conversation history minus the last user message — input for
     * {@see \NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper}.
     *
     * The last user message is the one the agent is responding to *this*
     * run; the rest is the prior turns the agent needs as seed for ReAct
     * continuity (D15). If no user message is present, returns the whole
     * list — `lastUserMessage()` already raises 400 upstream in that case.
     *
     * @return list<Message>
     */
    public function historyMessages(): array
    {
        $lastUser = $this->findLastUserIndex();
        if ($lastUser === null) {
            return $this->messages;
        }

        return array_slice($this->messages, 0, $lastUser);
    }

    private function findLastUserIndex(): int|null
    {
        $lastIndex = null;
        foreach ($this->messages as $index => $message) {
            if (!$message instanceof UserMessage) {
                continue;
            }

            $lastIndex = $index;
        }

        return $lastIndex;
    }

    /**
     * Tool names the client declared as available this run.
     * Intersected with the agent's known tools by {@see \NaokiTsuchiya\BEARAgUi\AgUiRunner}.
     *
     * @return list<string>
     */
    public function declaredToolNames(): array
    {
        return array_map(static fn(Tool $tool): string => $tool->name, $this->tools);
    }
}
