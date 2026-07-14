<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

use function str_rot13;

/**
 * PHP ships a Caesar cipher as a one-liner built-in — most PHP developers
 * have never called it. str_rot13() is its own inverse, so calling this
 * tool twice on the same text round-trips it: a fun beat for a live demo.
 *
 * ALPS marks `rot13_get` as `safe`, so it's confirm-free and joins the
 * parallel wave alongside the other read-only tools.
 */
final class Rot13 extends ResourceObject
{
    /** @param string $text Text to run through ROT13 */
    #[Tool(
        name: 'rot13_get',
        description: "Encode or decode text with PHP's built-in str_rot13() (a Caesar cipher — its own inverse)",
        confirm: false,
    )]
    public function onGet(string $text): static
    {
        $this->body = [
            'input' => $text,
            'output' => str_rot13($text),
        ];

        return $this;
    }
}
