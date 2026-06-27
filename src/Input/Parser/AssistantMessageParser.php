<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\AssistantMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

/**
 * Validates the body of an AssistantMessage. Called by {@see MessageParser}
 * with the already-validated `id`. `content` and `toolCalls` are both
 * optional (an "empty" assistant turn is technically legal and gets
 * skipped downstream by the history mapper). Each `toolCalls[]` entry is
 * validated by {@see AssistantToolCallParser}; a malformed entry aborts
 * with a `toolCalls[N].<field>` path.
 *
 * @internal
 */
final class AssistantMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     */
    public static function parseBody(string $id, array $data): AssistantMessage|ParseError
    {
        $toolCalls = [];
        foreach (Coerce::listOfObjects($data['toolCalls'] ?? []) as $index => $rawCall) {
            $call = AssistantToolCallParser::parse($rawCall);
            if ($call instanceof ParseError) {
                return $call->prefix("toolCalls[{$index}]");
            }

            $toolCalls[] = $call;
        }

        return new AssistantMessage($id, Coerce::nullableString($data['content'] ?? null), $toolCalls);
    }
}
