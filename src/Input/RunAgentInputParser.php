<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use Closure;
use JsonException;
use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\Message\UserMessage;
use NaokiTsuchiya\BEARAgUi\Input\Parser\ContextParser;
use NaokiTsuchiya\BEARAgUi\Input\Parser\MessageParser;
use NaokiTsuchiya\BEARAgUi\Input\Parser\ResumeParser;
use NaokiTsuchiya\BEARAgUi\Input\Parser\ToolParser;

use function array_map;
use function array_slice;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Validates and constructs a {@see RunAgentInput} from a raw JSON request body.
 *
 * Orchestrator only: it owns the top-level envelope (JSON decode,
 * `threadId` / `runId` / `messages` presence, optional-field coercion) and
 * delegates each list to a dedicated parser in {@see Parser}. Each per-VO
 * parser is the single place that knows that VO's wire shape; this class
 * never reaches into a VO field directly.
 *
 * Failures flow through the return type as {@see ParseError} (the parser
 * is total — no exceptions). Per-entry errors carry their structural path
 * (e.g. `messages[2].toolCallId is required`).
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 *
 * Each helper is short and single-purpose; the class CC / defect score is
 * the price of the no-throw Result pipeline (decode → validateRequired →
 * map each list → split trigger → build), where every step short-circuits
 * on a ParseError.
 *
 * @internal
 */
final class RunAgentInputParser
{
    public function parse(string $body): RunAgentInput|ParseError
    {
        $data = self::decode($body);
        if ($data instanceof ParseError) {
            return $data;
        }

        $required = self::validateRequired($data);
        if ($required instanceof ParseError) {
            return $required;
        }

        $messages = self::mapList('messages', $data['messages'], MessageParser::parse(...));
        if ($messages instanceof ParseError) {
            return $messages;
        }

        $tools = self::mapList('tools', $data['tools'] ?? [], ToolParser::parse(...));
        if ($tools instanceof ParseError) {
            return $tools;
        }

        $context = self::mapList('context', $data['context'] ?? [], ContextParser::parse(...));
        if ($context instanceof ParseError) {
            return $context;
        }

        $resume = self::mapList('resume', $data['resume'] ?? [], ResumeParser::parse(...));
        if ($resume instanceof ParseError) {
            return $resume;
        }

        // Split messages[] into the run trigger (latest user message text)
        // and the prior history. Run-readiness — a non-empty trigger must
        // exist — is validated here so the host's gate stays at the parse
        // boundary and RunAgentInput can be pure data.
        $trigger = self::splitTrigger($messages);
        if ($trigger instanceof ParseError) {
            return $trigger;
        }

        return new RunAgentInput(
            threadId: $required['threadId'],
            runId: $required['runId'],
            userMessage: $trigger['userMessage'],
            history: $trigger['history'],
            declaredToolNames: array_map(static fn(Tool $tool): string => $tool->name, $tools),
            context: $context,
            state: Coerce::assocOrNull($data['state'] ?? null),
            forwardedProps: Coerce::assoc($data['forwardedProps'] ?? null),
            resume: $resume,
        );
    }

    /**
     * Find the latest {@see UserMessage} (the run trigger) and slice the
     * turns before it as history. Fails when there is no user message or
     * its text is empty.
     *
     * @param list<Message> $messages
     *
     * @return array{userMessage: non-empty-string, history: list<Message>}|ParseError
     */
    private static function splitTrigger(array $messages): array|ParseError
    {
        $userMessage = null;
        $historyEnd = 0;
        foreach ($messages as $index => $message) {
            if ($message instanceof UserMessage) {
                $userMessage = $message->text;
                $historyEnd = $index;
            }
        }

        if ($userMessage === null || $userMessage === '') {
            return new ParseError('messages[] must contain a user message with text content.');
        }

        return ['userMessage' => $userMessage, 'history' => array_slice($messages, 0, $historyEnd)];
    }

    /** @return array<array-key, mixed>|ParseError */
    private static function decode(string $body): array|ParseError
    {
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new ParseError('Invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            return new ParseError('RunAgentInput must be a JSON object.');
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array{threadId: string, runId: string}|ParseError
     */
    private static function validateRequired(array $data): array|ParseError
    {
        $threadId = self::requireString($data, 'threadId');
        if ($threadId instanceof ParseError) {
            return $threadId;
        }

        $runId = self::requireString($data, 'runId');
        if ($runId instanceof ParseError) {
            return $runId;
        }

        if (!is_array($data['messages'] ?? null)) {
            return new ParseError("Missing or invalid 'messages'.");
        }

        return ['threadId' => $threadId, 'runId' => $runId];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return non-empty-string|ParseError
     */
    private static function requireString(array $data, string $key): string|ParseError
    {
        $value = Coerce::nonEmptyString($data[$key] ?? null);
        if ($value === null) {
            return new ParseError("Missing or invalid '{$key}'.");
        }

        return $value;
    }

    /**
     * Coerce `$raw` to a list of object-shaped entries and route each through
     * `$parse`. A {@see ParseError} from any entry aborts the whole list
     * with a path-prefixed error so the host can return HTTP 400 with the
     * exact field location.
     *
     * @template T of object
     *
     * @param Closure(array<string, mixed>): (T|ParseError) $parse
     *
     * @return list<T>|ParseError
     */
    private static function mapList(string $field, mixed $raw, Closure $parse): array|ParseError
    {
        $list = [];
        foreach (Coerce::listOfObjects($raw) as $index => $entry) {
            $value = $parse($entry);
            if ($value instanceof ParseError) {
                return $value->prefix("{$field}[{$index}]");
            }

            $list[] = $value;
        }

        return $list;
    }
}
