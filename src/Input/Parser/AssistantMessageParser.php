<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

/**
 * Validates the body of an AssistantMessage. Called by {@see MessageParser}
 * with the already-validated `id`. `content` and `toolCalls` are both
 * optional (an "empty" assistant turn is technically legal and gets
 * skipped downstream by the history mapper). Each `toolCalls[]` entry is
 * validated by {@see AssistantToolCallParser}; each malformed entry
 * contributes a `toolCalls[N].<field>` error; all entries are visited and
 * the errors aggregated.
 *
 * @internal
 */
final class AssistantMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     *
     * @return Result<AssistantMessage, list<ParseError>>
     */
    public static function parseBody(string $id, array $data): Result
    {
        $toolCalls = [];
        $errors = [];
        foreach (Coerce::listOfObjects($data['toolCalls'] ?? []) as $index => $rawCall) {
            $call = AssistantToolCallParser::parse($rawCall);
            $callIsOk = $call->isOk();
            if (!$callIsOk) {
                foreach ($call->unwrapErr() as $error) {
                    $errors[] = $error->prefix("toolCalls[{$index}]");
                }

                continue;
            }

            $toolCalls[] = $call->unwrap();
        }

        if ($errors !== []) {
            return Result::err($errors);
        }

        return Result::ok(new AssistantMessage($id, Coerce::nullableString($data['content'] ?? null), $toolCalls));
    }
}
