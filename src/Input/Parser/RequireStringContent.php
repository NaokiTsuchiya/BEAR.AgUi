<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\ParseError;

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
    /** @param array<string, mixed> $data */
    public static function from(array $data): string|ParseError
    {
        if (!array_key_exists('content', $data) || !is_string($data['content'])) {
            return new ParseError('content is required');
        }

        return $data['content'];
    }
}
