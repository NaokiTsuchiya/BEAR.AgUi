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
     * @return Result<ActivityMessage, list<ParseError>>
     */
    public static function parseBody(string $id, array $data): Result
    {
        $errors = [];

        $activityType = Coerce::nonEmptyString($data['activityType'] ?? null);
        if ($activityType === null) {
            $errors[] = new ParseError('activityType is required');
        }

        $content = Coerce::stringKeyedArray($data['content'] ?? null);
        if ($content === null) {
            $errors[] = new ParseError(
                array_key_exists('content', $data) ? 'content must be a string-keyed object' : 'content is required',
            );
        }

        if ($errors !== [] || $activityType === null || $content === null) {
            return Result::err($errors);
        }

        return Result::ok(new ActivityMessage($id, $activityType, $content));
    }
}
