<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use InvalidArgumentException;

use function array_key_exists;
use function count;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

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
        public array $tools = [],
        public array $context = [],
        public ?array $state = null,
        public array $forwardedProps = [],
        public array $resume = [],
    ) {}

    /**
     * Build + validate from a raw JSON request body.
     *
     * @throws InvalidArgumentException on malformed input (→ HTTP 400 upstream).
     * @throws \JsonException when the body is not valid JSON.
     */
    public static function fromJson(string $body): self
    {
        /** @var mixed $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('RunAgentInput must be a JSON object.');
        }

        foreach (['threadId', 'runId'] as $required) {
            if (!array_key_exists($required, $data) || !is_string($data[$required]) || $data[$required] === '') {
                throw new InvalidArgumentException("Missing or invalid '{$required}'.");
            }
        }

        if (!isset($data['messages']) || !is_array($data['messages'])) {
            throw new InvalidArgumentException("Missing or invalid 'messages'.");
        }

        /** @var array<string, mixed>|null $state */
        $state = is_array($data['state'] ?? null) ? $data['state'] : null;
        /** @var array<string, mixed> $forwardedProps */
        $forwardedProps = is_array($data['forwardedProps'] ?? null) ? $data['forwardedProps'] : [];

        return new self(
            threadId: (string) $data['threadId'],
            runId: (string) $data['runId'],
            messages: self::ensureListOfObjects($data['messages']),
            tools: self::ensureListOfObjects($data['tools'] ?? []),
            context: self::ensureListOfObjects($data['context'] ?? []),
            state: $state,
            forwardedProps: $forwardedProps,
            resume: self::ensureListOfObjects($data['resume'] ?? []),
        );
    }

    /**
     * Coerce an arbitrary value into a list of associative arrays. Non-array
     * inputs become an empty list; non-array entries are dropped.
     *
     * @return list<array<string, mixed>>
     */
    private static function ensureListOfObjects(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $list[] = $entry;
            }
        }

        return $list;
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
            if (($message['role'] ?? null) === 'user' && isset($message['content']) && is_string($message['content'])) {
                return $message['content'];
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
            if (!(isset($tool['name']) && is_string($tool['name']))) {
                continue;
            }

            $names[] = $tool['name'];
        }

        return $names;
    }
}
