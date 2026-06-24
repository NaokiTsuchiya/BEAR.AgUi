<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

/**
 * The data the adapter needs to render a TOOL_CALL_ARGS / TOOL_CALL_RESULT pair.
 *
 * `input` is the JSON-string the LLM streamed for the call's arguments (a single
 * fragment captured at dispatch time, which is enough for v1 — argument
 * streaming as it arrives is future work). `content` is the stringified body
 * the dispatcher returned (or an error message when isError is true).
 *
 * @api
 */
final readonly class ToolCallOutcome
{
    public function __construct(
        public string $input,
        public string $content,
        public bool $isError,
    ) {}
}
