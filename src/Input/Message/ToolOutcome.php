<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

/**
 * Result-style wrapper for the success / failure half of a {@see ToolMessage}.
 *
 * AG-UI's wire shape stores `content` (always) and an optional `error`; the
 * presence of `error` discriminates outcome. Modelling that as a wrapper VO
 * keeps `ToolMessage` itself a single concrete class — consumers ask the
 * outcome directly (`$msg->outcome->isError()`) instead of pattern-matching
 * the message type, and the success/failure construction goes through named
 * factories so callers can't forget which field belongs to which case.
 *
 * @api
 */
final readonly class ToolOutcome
{
    /** Use {@see success()} or {@see failure()} — the constructor is private to enforce the invariant. */
    private function __construct(
        public mixed $content,
        public string|null $error,
    ) {}

    /** Tool executed successfully; `content` is its payload. */
    public static function success(mixed $content): self
    {
        return new self($content, null);
    }

    /**
     * Tool execution failed; `error` carries the human-readable failure
     * message, and `content` may still hold a structured diagnostic.
     */
    public static function failure(mixed $content, string $error): self
    {
        return new self($content, $error);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }
}
