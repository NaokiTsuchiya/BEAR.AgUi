<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

/**
 * Static-method contract every per-role message parser must satisfy so
 * {@see MessageParser} can dispatch on `role` without each variant drifting
 * from the shared `(id, data) -> Result<Message, ParseError>` shape.
 *
 * Implementations narrow the success parameter via {@see Result}'s
 * covariance — e.g. the user parser returns `Result<UserMessage, ParseError>`
 * — and PHP enforces the signature at class load, so adding a new variant
 * without implementing this interface is a fatal error rather than a silent
 * dispatch gap.
 *
 * @internal
 */
interface MessageVariantParser
{
    /**
     * Validate the body of one message variant (the common `id` check has
     * already run in {@see MessageParser::parse()}).
     *
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     *
     * @return Result<Message, ParseError>
     */
    public static function parseBody(string $id, array $data): Result;
}
