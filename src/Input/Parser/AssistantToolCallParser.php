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
 * @mago-expect lint:cyclomatic-complexity
 *
 * The CC score is the price of aggregating the independent `id` and the
 * `function`-dependent `name` / `arguments` errors into one list rather
 * than short-circuiting on the first failure.
 *
 * @internal
 */
final class AssistantToolCallParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @return Result<AssistantToolCall, list<ParseError>>
     */
    public static function parse(array $data): Result
    {
        $errors = [];

        $id = RequireId::from($data);
        $idIsOk = $id->isOk();
        $idValue = null;
        if ($idIsOk) {
            $idValue = $id->unwrap();
        }

        if (!$idIsOk) {
            foreach ($id->unwrapErr() as $error) {
                $errors[] = $error;
            }
        }

        [$name, $arguments, $functionErrors] = self::parseFunction($data['function'] ?? null);
        foreach ($functionErrors as $error) {
            $errors[] = $error;
        }

        if ($errors !== [] || $idValue === null || $name === null || $arguments === null) {
            return Result::err($errors);
        }

        return Result::ok(new AssistantToolCall($idValue, $name, $arguments));
    }

    /**
     * @return array{non-empty-string|null, array<string, mixed>|null, list<ParseError>}
     */
    private static function parseFunction(mixed $function): array
    {
        if (!is_array($function)) {
            return [null, null, [new ParseError('function is required')]];
        }

        /** @var array<string, mixed> $function */
        $errors = [];

        $name = Coerce::nonEmptyString($function['name'] ?? null);
        if ($name === null) {
            $errors[] = new ParseError('function.name is required');
        }

        $decoded = self::decodeArguments($function);
        $decodedIsOk = $decoded->isOk();
        $arguments = null;
        if ($decodedIsOk) {
            $arguments = $decoded->unwrap();
        }

        if (!$decodedIsOk) {
            foreach ($decoded->unwrapErr() as $error) {
                $errors[] = $error;
            }
        }

        return [$name, $arguments, $errors];
    }

    /**
     * @param array<string, mixed> $function
     *
     * @return Result<array<string, mixed>, list<ParseError>>
     */
    private static function decodeArguments(array $function): Result
    {
        if (!array_key_exists('arguments', $function)) {
            return Result::err([new ParseError('function.arguments is required')]);
        }

        $raw = Coerce::nullableString($function['arguments']);
        if ($raw === null) {
            return Result::err([new ParseError('function.arguments must be a string')]);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return Result::err([new ParseError('function.arguments is not valid JSON: ' . $e->getMessage())]);
        }

        $args = Coerce::stringKeyedArray($decoded);
        if ($args === null) {
            return Result::err([new ParseError('function.arguments must decode to a JSON object')]);
        }

        return Result::ok($args);
    }
}
