<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Parser;

use NaokiTsuchiya\BEARAgUi\Input\Coerce;
use NaokiTsuchiya\BEARAgUi\Input\Message\ActivityMessage;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\Result;

use function array_key_exists;

/**
 * Validates the body of an ActivityMessage. Called by {@see MessageParser}
 * with the already-validated `id`. `activityType` and `content`
 * (associative `Record<string, any>`) are both required per spec.
 *
 * @internal
 */
final class ActivityMessageParser implements MessageVariantParser
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data
     *
     * @return Result<ActivityMessage, ParseError>
     */
    public static function parseBody(string $id, array $data): Result
    {
        $activityType = Coerce::nonEmptyString($data['activityType'] ?? null);
        if ($activityType === null) {
            return Result::err(new ParseError('activityType is required'));
        }

        if (!array_key_exists('content', $data)) {
            return Result::err(new ParseError('content is required'));
        }

        $content = Coerce::stringKeyedArray($data['content']);
        if ($content === null) {
            return Result::err(new ParseError('content must be a string-keyed object'));
        }

        return Result::ok(new ActivityMessage($id, $activityType, $content));
    }
}
