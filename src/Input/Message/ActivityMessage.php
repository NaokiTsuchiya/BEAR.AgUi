<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use Override;

/**
 * AG-UI ActivityMessage — observability metadata about the run, not chat
 * history. The mapper skips it; this VO is retained to preserve message
 * order for callers that walk `$messages` directly.
 *
 * @api
 */
final readonly class ActivityMessage implements Message
{
    /** @param array<string, mixed> $content */
    public function __construct(
        public string $id,
        public string $activityType,
        public array $content,
    ) {}

    #[Override]
    public function role(): string
    {
        return 'activity';
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}
