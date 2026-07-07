<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

use function array_key_exists;
use function is_string;

/**
 * Shared `content` (required, string) check used by SystemMessage,
 * DeveloperMessage, and ReasoningMessage parsers. Like {@see RequireId},
 * pulled into a single helper so the validation stays in lockstep across
 * variants.
 *
 * @internal
 */
final class RequireStringContent
{
    /**
     * @param array<string, mixed> $data
     *
     * @return Result<string, list<ParseError>>
     */
    public static function from(array $data): Result
    {
        if (!array_key_exists('content', $data) || !is_string($data['content'])) {
            return Result::err([new ParseError('content is required')]);
        }

        return Result::ok($data['content']);
    }
}
