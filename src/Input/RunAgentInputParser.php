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
use function array_merge;
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
 * Failures flow through the return type as a **non-empty `list<ParseError>`**
 * (the parser is total — no exceptions). Errors are *aggregated* across the
 * independent siblings — `threadId`, `runId`, and each entry of
 * `messages[]` / `tools[]` / `context[]` / `resume[]` — so the host can
 * report every structural problem at once (D24, level ii). Each per-entry
 * error carries its structural path (e.g. the message that is missing a
 * `toolCallId`). Short-circuiting is limited to the two true dependencies:
 * a JSON decode failure (no structure left to inspect) and the trigger
 * split (which needs a cleanly parsed message list).
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 *
 * The class CC / defect score is the price of the no-throw aggregation
 * pipeline (decode → collect required + map each list → split trigger),
 * where only decode and the trigger split short-circuit.
 *
 * @internal
 */
final class RunAgentInputParser
{
    /**
     * Parse the body into a run-ready {@see RunAgentInput}, or a non-empty
     * `list<ParseError>` aggregating every structural problem.
     *
     * Callers discriminate on the type: `is_array($result)` means failure.
     *
     * @return RunAgentInput|list<ParseError>
     */
    public function parse(string $body): RunAgentInput|array
    {
        $data = self::decode($body);
        if ($data instanceof ParseError) {
            return [$data];
        }

        $threadId = Coerce::nonEmptyString($data['threadId'] ?? null);
        $runId = Coerce::nonEmptyString($data['runId'] ?? null);

        $errors = [];
        if ($threadId === null) {
            $errors[] = new ParseError("Missing or invalid 'threadId'.");
        }

        if ($runId === null) {
            $errors[] = new ParseError("Missing or invalid 'runId'.");
        }

        [$messages, $messageErrors] = self::parseMessages($data['messages'] ?? null);
        $errors = array_merge($errors, $messageErrors);

        [$tools, $toolErrors] = self::mapList('tools', $data['tools'] ?? [], ToolParser::parse(...));
        [$context, $contextErrors] = self::mapList('context', $data['context'] ?? [], ContextParser::parse(...));
        [$resume, $resumeErrors] = self::mapList('resume', $data['resume'] ?? [], ResumeParser::parse(...));
        $errors = array_merge($errors, $toolErrors, $contextErrors, $resumeErrors);

        // Any independent-sibling failure → report them all. The `=== null`
        // guards are redundant at runtime (a null scalar already pushed an
        // error, so the list is non-empty) but let the analyzer carry
        // threadId / runId as non-empty strings into the constructor below.
        if ($errors !== [] || $threadId === null || $runId === null) {
            return $errors;
        }

        // Dependent step: the trigger split needs a cleanly parsed message
        // list, so its "non-empty user message required" check can only run
        // once the siblings above are clean.
        $trigger = self::splitTrigger($messages);
        if ($trigger instanceof ParseError) {
            return [$trigger];
        }

        return new RunAgentInput(
            threadId: $threadId,
            runId: $runId,
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

    /**
     * Parse the required `messages[]` list, or report its absence. Kept in
     * its own helper so {@see parse()} stays free of the present / absent
     * branch.
     *
     * @return array{list<Message>, list<ParseError>}
     */
    private static function parseMessages(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [[], [new ParseError("Missing or invalid 'messages'.")]];
        }

        return self::mapList('messages', $raw, MessageParser::parse(...));
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
     * Coerce `$raw` to a list of object-shaped entries, route each through
     * `$parse`, and split the outcomes into the successfully parsed values
     * and the path-prefixed errors. Unlike a fail-fast map, *every* entry is
     * visited so the caller can aggregate all errors (D24); a failing entry
     * is recorded with its `field[index]` path and skipped.
     *
     * @template T of object
     *
     * @param Closure(array<string, mixed>): (T|ParseError) $parse
     *
     * @return array{list<T>, list<ParseError>}
     */
    private static function mapList(string $field, mixed $raw, Closure $parse): array
    {
        $values = [];
        $errors = [];
        foreach (Coerce::listOfObjects($raw) as $index => $entry) {
            $result = $parse($entry);
            if ($result instanceof ParseError) {
                $errors[] = $result->prefix("{$field}[{$index}]");

                continue;
            }

            $values[] = $result;
        }

        return [$values, $errors];
    }
}
