<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;
use NaokiTsuchiya\BEARAgUi\Input\Resume;

/**
 * Validates a raw AG-UI `Resume` entry. `interruptId` and `status` are
 * required per spec; `payload` is optional and may be any JSON value.
 *
 * @internal
 */
final class ResumeParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @return Result<Resume, ParseError>
     */
    public static function parse(array $data): Result
    {
        $interruptId = Coerce::nonEmptyString($data['interruptId'] ?? null);
        if ($interruptId === null) {
            return Result::err(new ParseError('interruptId is required'));
        }

        $status = Coerce::nonEmptyString($data['status'] ?? null);
        if ($status === null) {
            return Result::err(new ParseError('status is required'));
        }

        return Result::ok(new Resume($interruptId, $status, $data['payload'] ?? null));
    }
}
