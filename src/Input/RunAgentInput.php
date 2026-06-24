<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use InvalidArgumentException;
use JsonException;

use function count;
use function is_string;

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
 * v1 reads only threadId / runId / messages / tools. state, forwardedProps
 * and resume are accepted leniently to stay forward-compatible.
 *
 * @api
 */
final readonly class RunAgentInput
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $context
     * @param array<string, mixed>|null  $state
     * @param array<string, mixed>       $forwardedProps
     * @param list<array<string, mixed>> $resume
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
     * Build + validate from a raw JSON request body.
     *
     * Thin facade over {@see RunAgentInputParser::parse()} — kept on this
     * class so callers (and tests) have one obvious entry point.
     *
     * @throws InvalidArgumentException on malformed input (→ HTTP 400 upstream).
     * @throws JsonException when the body is not valid JSON.
     */
    public static function fromJson(string $body): self
    {
        return (new RunAgentInputParser())->parse($body);
    }

    /**
     * The text of the last user message — what ToolUse runStream() takes.
     *
     * Full message-history mapping (multimodal content, tool messages) is
     * scoped to M1 (see decisions D15/D17).
     *
     * @throws InvalidArgumentException when no user message with string content exists.
     */
    public function lastUserMessage(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $message = $this->messages[$i];
            $content = $message['content'] ?? null;
            if (($message['role'] ?? null) === 'user' && is_string($content)) {
                return $content;
            }
        }

        throw new InvalidArgumentException('No user message with string content found in messages[].');
    }

    /**
     * Tool names the client declared as available this run.
     * Maps to ToolUse AgentOptions::withTools($names).
     *
     * @return list<string>
     */
    public function declaredToolNames(): array
    {
        $names = [];
        foreach ($this->tools as $tool) {
            $name = $tool['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }
}
