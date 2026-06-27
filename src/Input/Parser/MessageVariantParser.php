<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Message\Message;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;

/**
 * Static-method contract every per-role message parser must satisfy so
 * {@see MessageParser} can dispatch on `role` without each variant drifting
 * from the shared `(id, data) -> Message|ParseError` shape.
 *
 * Implementations narrow the return type via covariance — e.g. the user
 * parser returns `UserMessage|ParseError` — and PHP enforces the
 * signature at class load, so adding a new variant without implementing
 * this interface is a fatal error rather than a silent dispatch gap.
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
     */
    public static function parseBody(string $id, array $data): Message|ParseError;
}
