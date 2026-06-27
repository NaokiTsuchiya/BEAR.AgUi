<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use NaokiTsuchiya\BEARAgUi\Input\Message\Message;

/**
 * AG-UI RunAgentInput as a parsed, run-ready value object.
 *
 * Built only by {@see RunAgentInputParser}, which does all the derivation:
 * it splits the wire `messages[]` into the trigger (`userMessage`, the
 * latest user message's text) and the prior `history`, and projects
 * `tools[]` down to `declaredToolNames` (D16). This class therefore stays
 * pure data — no projection methods, no construction-time computation.
 *
 * `state` / `forwardedProps` stay associative arrays because they are
 * intentionally free-form per the spec; `context` / `resume` are accepted
 * for forward-compat but unread in v1.
 *
 * @api
 */
final readonly class RunAgentInput
{
    /**
     * @param non-empty-string     $userMessage       latest user message text (the run trigger)
     * @param list<Message>        $history           prior turns (everything before the trigger)
     * @param list<string>         $declaredToolNames client-declared tool names (D16)
     * @param list<Context>        $context
     * @param array<string, mixed>|null $state
     * @param array<string, mixed> $forwardedProps
     * @param list<Resume>         $resume
     *
     * @mago-expect lint:excessive-parameter-list
     *
     * The constructor mirrors the AG-UI run request (identity + the parsed
     * projections the runner consumes); splitting it into sub-DTOs would
     * drift from the spec.
     */
    public function __construct(
        public string $threadId,
        public string $runId,
        public string $userMessage,
        public array $history,
        public array $declaredToolNames,
        public array $context,
        public array|null $state,
        public array $forwardedProps,
        public array $resume,
    ) {}
}
