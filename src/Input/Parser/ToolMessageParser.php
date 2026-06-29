<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolMessage;
use NaokiTsuchiya\BEARAgUi\Input\Message\ToolOutcome;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

use function array_key_exists;

/**
 * Validates the body of a ToolMessage and routes the wire-level `error`
 * discriminator into the success / failure factory on {@see ToolOutcome},
 * so the consumer sees a single {@see ToolMessage} concrete type and asks
 * the wrapped outcome directly. Per spec, `toolCallId` and `content` are
 * required; `error` is optional — present (and non-null) marks the call
 * as failed.
 *
 * Called by {@see MessageParser} with the already-validated `id`.
 *
 * @internal
 */
final class ToolMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     *
     * @return Result<ToolMessage, ParseError>
     */
    public static function parseBody(string $id, array $data): Result
    {
        $toolCallId = Coerce::nonEmptyString($data['toolCallId'] ?? null);
        if ($toolCallId === null) {
            return Result::err(new ParseError('toolCallId is required'));
        }

        if (!array_key_exists('content', $data)) {
            return Result::err(new ParseError('content is required'));
        }

        $content = $data['content'];

        $outcome = array_key_exists('error', $data) && $data['error'] !== null
            ? ToolOutcome::failure($content, Coerce::string($data['error']))
            : ToolOutcome::success($content);

        return Result::ok(new ToolMessage($id, $toolCallId, $outcome));
    }
}
