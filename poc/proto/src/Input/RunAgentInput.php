<?php

declare(strict_types=1);

namespace BEAR\AgUi\Input;

use InvalidArgumentException;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * AG-UI RunAgentInput as a typed value object (ADR 0001).
 *
 * Wire shape:
 *   { threadId, runId, messages[], tools[], context[], state{}, forwardedProps{} }
 *
 * AgentCore passes the POST body unvalidated, so validation is OUR responsibility
 * and happens here, before the stream starts. A failure here is a connection-level
 * error (HTTP 400 VALIDATION_ERROR) — distinct from a RUN_ERROR mid-stream.
 *
 * This prototype does structural validation by hand; the real resource would put
 * a JsonSchema on the parameter and let BEAR validate before onPost() runs.
 *
 * @psalm-type Msg = array{id?: string, role: string, content: string}
 */
final readonly class RunAgentInput
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $context
     * @param array<string, mixed>       $state
     * @param array<string, mixed>       $forwardedProps
     */
    public function __construct(
        public string $threadId,
        public string $runId,
        public array $messages,
        public array $tools = [],
        public array $context = [],
        public array $state = [],
        public array $forwardedProps = [],
    ) {
    }

    /**
     * Build + validate from a raw JSON request body.
     *
     * @throws InvalidArgumentException on malformed input (-> HTTP 400 upstream).
     */
    public static function fromJson(string $body): self
    {
        /** @var mixed $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('RunAgentInput must be a JSON object.');
        }

        foreach (['threadId', 'runId'] as $required) {
            if (! array_key_exists($required, $data) || ! is_string($data[$required]) || $data[$required] === '') {
                throw new InvalidArgumentException("Missing or invalid '{$required}'.");
            }
        }

        if (! isset($data['messages']) || ! is_array($data['messages'])) {
            throw new InvalidArgumentException("Missing or invalid 'messages'.");
        }

        /** @var array<string, mixed> $data */
        return new self(
            threadId: (string) $data['threadId'],
            runId: (string) $data['runId'],
            messages: $data['messages'],
            tools: is_array($data['tools'] ?? null) ? $data['tools'] : [],
            context: is_array($data['context'] ?? null) ? $data['context'] : [],
            state: is_array($data['state'] ?? null) ? $data['state'] : [],
            forwardedProps: is_array($data['forwardedProps'] ?? null) ? $data['forwardedProps'] : [],
        );
    }

    /**
     * The text of the last user message — what ToolUse runStream() takes.
     * (Full message-history mapping is out of scope for the prototype.)
     */
    public function lastUserMessage(): string
    {
        for ($i = $this->messages !== [] ? count($this->messages) - 1 : -1; $i >= 0; $i--) {
            $m = $this->messages[$i];
            if (($m['role'] ?? null) === 'user' && isset($m['content']) && is_string($m['content'])) {
                return $m['content'];
            }
        }

        throw new InvalidArgumentException('No user message found in messages[].');
    }

    /**
     * Tool names the client declared as available this run.
     * Maps to ToolUse AgentOptions::withTools($names) (PR #22).
     *
     * @return list<string>
     */
    public function declaredToolNames(): array
    {
        $names = [];
        foreach ($this->tools as $tool) {
            if (isset($tool['name']) && is_string($tool['name'])) {
                $names[] = $tool['name'];
            }
        }

        return $names;
    }
}
