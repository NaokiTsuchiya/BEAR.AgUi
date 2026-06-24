<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

/**
 * Common contract for every AG-UI message variant in RunAgentInput.messages[].
 *
 * The AG-UI wire schema discriminates messages by `role` and gives each role
 * its own field set ({@see UserMessage}, {@see AssistantMessage},
 * {@see ToolMessage}, …). This interface lets callers iterate
 * `list<Message>` and use `instanceof` to dispatch, instead of poking at raw
 * arrays.
 *
 * @api
 */
interface Message
{
    /** Wire role string — `"user"`, `"assistant"`, etc. */
    public function role(): string;

    /** AG-UI message id (may be `""` if the producer omitted it). */
    public function id(): string;
}
