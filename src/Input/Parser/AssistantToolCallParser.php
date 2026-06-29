<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use JsonException;
use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantToolCall;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Validates one OpenAI-shaped `{ id, type, function: { name, arguments } }`
 * tool-call entry from an AssistantMessage history record.
 *
 * Per spec the wire shape requires `id`, `function`, `function.name`, and
 * `function.arguments` (the last being a JSON-encoded object string). The
 * parser decodes `arguments` here so the resulting {@see AssistantToolCall}
 * exposes an already-typed `array<string, mixed>` — invalid JSON or a non-
 * object payload raises a {@see ParseError} rather than silently degrading
 * to `[]` and hiding history corruption from ReAct downstream.
 *
 * @internal
 */
final class AssistantToolCallParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @return Result<AssistantToolCall, ParseError>
     */
    public static function parse(array $data): Result
    {
        $id = RequireId::from($data);
        if (!$id->isOk()) {
            return Result::err($id->unwrapErr());
        }

        $idValue = $id->unwrap();
        $function = $data['function'] ?? null;
        if (!is_array($function)) {
            return Result::err(new ParseError('function is required'));
        }

        $name = Coerce::nonEmptyString($function['name'] ?? null);
        if ($name === null) {
            return Result::err(new ParseError('function.name is required'));
        }

        if (!array_key_exists('arguments', $function)) {
            return Result::err(new ParseError('function.arguments is required'));
        }

        $arguments = self::decodeArguments($function['arguments']);
        if (!$arguments->isOk()) {
            return Result::err($arguments->unwrapErr());
        }

        return Result::ok(new AssistantToolCall($idValue, $name, $arguments->unwrap()));
    }

    /** @return Result<array<string, mixed>, ParseError> */
    private static function decodeArguments(mixed $raw): Result
    {
        if (!is_string($raw)) {
            return Result::err(new ParseError('function.arguments must be a string'));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return Result::err(new ParseError('function.arguments is not valid JSON: ' . $e->getMessage()));
        }

        $args = Coerce::stringKeyedArray($decoded);
        if ($args === null) {
            return Result::err(new ParseError('function.arguments must decode to a JSON object'));
        }

        return Result::ok($args);
    }
}
