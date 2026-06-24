<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use Override;

/**
 * AG-UI UserMessage, projected to plain text up-front by
 * {@see \NaokiTsuchiya\BEARAgUi\Input\Parser\UserMessageParser}.
 *
 * AG-UI's wire `content` is `string | InputContent[]` (D17); v1 is text-
 * only, so the parser joins text parts with `\n` and drops non-text parts
 * (image/file) into `$text`. Empty results stay empty here — only
 * {@see \NaokiTsuchiya\BEARAgUi\Input\RunAgentInput::lastUserMessage()}
 * promotes an empty trailing user message into HTTP 400, since the same
 * empty `$text` is a tolerable history entry.
 *
 * @api
 */
final readonly class UserMessage implements Message
{
    public function __construct(
        public string $id,
        public string $text,
    ) {}

    #[Override]
    public function role(): string
    {
        return 'user';
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}
