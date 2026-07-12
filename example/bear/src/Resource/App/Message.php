<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

/**
 * Message sending, exposed as the `message_post` tool — the governance
 * demo (D27): ALPS marks it `unsafe`, so the safeAndIdempotent policy
 * strips it from every LLM request. The implementation exists and is
 * dispatchable, but the agent never sees the tool.
 */
final class Message extends ResourceObject
{
    /**
     * @param string $to   Recipient address
     * @param string $body Message body text
     */
    #[Tool(name: 'message_post', description: 'Send a message to a recipient', confirm: false)]
    public function onPost(string $to, string $body): static
    {
        $this->code = 201;
        $this->body = [
            'sent' => true,
            'to' => $to,
            'preview' => $body,
        ];

        return $this;
    }
}
