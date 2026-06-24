<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use Override;

/**
 * AG-UI SystemMessage. The library does not forward system prompts to
 * ToolUse from history (that is the host app's responsibility via
 * `systemPrompt`), so this VO exists only to keep `messages[]` round-trip
 * faithful for callers that need it.
 *
 * @api
 */
final readonly class SystemMessage implements Message
{
    public function __construct(
        public string $id,
        public string $content,
    ) {}

    #[Override]
    public function role(): string
    {
        return 'system';
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}
