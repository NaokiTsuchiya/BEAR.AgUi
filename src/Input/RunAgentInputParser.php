<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use InvalidArgumentException;
use JsonException;

use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Validates and constructs a {@see RunAgentInput} from a raw JSON request body.
 *
 * Kept separate so RunAgentInput itself stays close to the wire DTO and the
 * validation branches don't push the value object past the cyclomatic-
 * complexity threshold. Lenient coercion of optional fields lives in
 * {@see Coerce}.
 *
 * @internal
 */
final class RunAgentInputParser
{
    /**
     * @throws InvalidArgumentException on malformed input (→ HTTP 400 upstream).
     * @throws JsonException when the body is not valid JSON.
     */
    public function parse(string $body): RunAgentInput
    {
        /** @var mixed $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('RunAgentInput must be a JSON object.');
        }

        return new RunAgentInput(
            threadId: self::requireString($data, 'threadId'),
            runId: self::requireString($data, 'runId'),
            messages: self::requireMessages($data),
            tools: Coerce::listOfObjects($data['tools'] ?? []),
            context: Coerce::listOfObjects($data['context'] ?? []),
            state: Coerce::assocOrNull($data['state'] ?? null),
            forwardedProps: Coerce::assoc($data['forwardedProps'] ?? null),
            resume: Coerce::listOfObjects($data['resume'] ?? []),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing or invalid '{$key}'.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    private static function requireMessages(array $data): array
    {
        $messages = $data['messages'] ?? null;
        if (!is_array($messages)) {
            throw new InvalidArgumentException("Missing or invalid 'messages'.");
        }

        return Coerce::listOfObjects($messages);
    }
}
