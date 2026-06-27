<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Resume;

/**
 * Validates a raw AG-UI `Resume` entry. `interruptId` and `status` are
 * required per spec; `payload` is optional and may be any JSON value.
 *
 * @internal
 */
final class ResumeParser
{
    /** @param array<string, mixed> $data */
    public static function parse(array $data): Resume|ParseError
    {
        $interruptId = Coerce::nonEmptyString($data['interruptId'] ?? null);
        if ($interruptId === null) {
            return new ParseError('interruptId is required');
        }

        $status = Coerce::nonEmptyString($data['status'] ?? null);
        if ($status === null) {
            return new ParseError('status is required');
        }

        return new Resume($interruptId, $status, $data['payload'] ?? null);
    }
}
