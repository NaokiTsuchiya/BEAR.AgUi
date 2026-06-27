<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

/**
 * Failure outcome from {@see RunAgentInputParser::parse()} or
 * {@see RunAgentInput::lastUserMessage()}.
 *
 * Returned in a union (`RunAgentInput|ParseError`, `string|ParseError`) so
 * input-boundary failures flow through the type system instead of exception
 * channels. Maps to a connection-level HTTP 400 in
 * {@see \NaokiTsuchiya\BEARAgUi\AgUiRunner} (ADR 0001) — distinct from
 * RUN_ERROR mid-stream.
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
