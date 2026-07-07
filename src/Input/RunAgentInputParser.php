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
 * Failures flow through a {@see Result} carrying a **non-empty
 * `list<ParseError>`** (the parser is total — no exceptions). Errors are
 * *aggregated* across the independent siblings — `threadId`, `runId`, and
 * each entry of `messages[]` / `tools[]` / `context[]` / `resume[]` — so the
 * host can report every structural problem at once (D24, level ii). Each
 * per-entry error carries its structural path (e.g. the message that is
 * missing a `toolCallId`). Short-circuiting is limited to the two true
 * dependencies: a JSON decode failure (no structure left to inspect) and
 * the trigger split (which needs a cleanly parsed message list).
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
     * @return Result<RunAgentInput, list<ParseError>>
     */
    public function parse(string $body): Result
    {
        $decoded = self::decode($body);
        if (!$decoded->isOk()) {
            return Result::err($decoded->unwrapErr());
        }

        $data = $decoded->unwrap();

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
            return Result::err($errors);
        }

        // Dependent step: the trigger split needs a cleanly parsed message
        // list, so its "non-empty user message required" check can only run
        // once the siblings above are clean.
        $trigger = self::splitTrigger($messages);
        if (!$trigger->isOk()) {
            return Result::err($trigger->unwrapErr());
        }

        $triggerValue = $trigger->unwrap();

        return Result::ok(new RunAgentInput(
            threadId: $threadId,
            runId: $runId,
            userMessage: $triggerValue['userMessage'],
            history: $triggerValue['history'],
            declaredToolNames: array_map(static fn(Tool $tool): string => $tool->name, $tools),
            context: $context,
            state: Coerce::assocOrNull($data['state'] ?? null),
            forwardedProps: Coerce::assoc($data['forwardedProps'] ?? null),
            resume: $resume,
        ));
    }

    /**
     * Find the latest {@see UserMessage} (the run trigger) and slice the
     * turns before it as history. Fails when there is no user message or
     * its text is empty.
     *
     * @param list<Message> $messages
     *
     * @return Result<array{userMessage: non-empty-string, history: list<Message>}, list<ParseError>>
     */
    private static function splitTrigger(array $messages): Result
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
            return Result::err([new ParseError('messages[] must contain a user message with text content.')]);
        }

        return Result::ok(['userMessage' => $userMessage, 'history' => array_slice($messages, 0, $historyEnd)]);
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

    /** @return Result<array<array-key, mixed>, list<ParseError>> */
    private static function decode(string $body): Result
    {
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return Result::err([new ParseError('Invalid JSON: ' . $e->getMessage())]);
        }

        if (!is_array($data)) {
            return Result::err([new ParseError('RunAgentInput must be a JSON object.')]);
        }

        return Result::ok($data);
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
     * @param Closure(array<string, mixed>): Result<T, list<ParseError>> $parse
     *
     * @return array{list<T>, list<ParseError>}
     */
    private static function mapList(string $field, mixed $raw, Closure $parse): array
    {
        $values = [];
        $errors = [];
        foreach (Coerce::listOfObjects($raw) as $index => $entry) {
            $result = $parse($entry);
            if (!$result->isOk()) {
                foreach ($result->unwrapErr() as $error) {
                    $errors[] = $error->prefix("{$field}[{$index}]");
                }

                continue;
            }

            $values[] = $result->unwrap();
        }

        return [$values, $errors];
    }
}
