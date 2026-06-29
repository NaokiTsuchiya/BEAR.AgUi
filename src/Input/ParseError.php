<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

/**
 * One structural failure at the input boundary, carrying a human-readable
 * `message` and (via {@see prefix()}) the path to the offending field.
 *
 * {@see RunAgentInputParser::parse()} aggregates these into a non-empty
 * `list<ParseError>` and returns it in a union (`RunAgentInput|list<ParseError>`)
 * so input-boundary failures flow through the type system instead of
 * exception channels (D24). The host maps a returned list to a
 * connection-level HTTP 400 (ADR 0001) — distinct from RUN_ERROR mid-stream.
 *
 * @api
 */
final readonly class ParseError
{
    public function __construct(
        public string $message,
    ) {}

    /**
     * Prepend a structural path segment so deep errors carry enough context
     * to debug. `new ParseError('id is required')->prefix('messages[2]')`
     * yields `"messages[2].id is required"`.
     */
    public function prefix(string $context): self
    {
        return new self($context . '.' . $this->message);
    }
}
