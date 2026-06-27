<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\ToolUse;

use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Runtime\OptionAwareStreamingAgentInterface;

/**
 * Per-run factory the {@see \NaokiTsuchiya\BEARAgUi\AgUiRunner} uses to
 * build a {@see OptionAwareStreamingAgentInterface} with the M0
 * recorder/decorator graph wired around the host's real LLM client and
 * dispatcher (D14).
 *
 * Why a factory seam, not a plain agent injection: `StreamingAgent` is
 * final and its dependencies are private, so the enrichment decorators
 * (D10) have to be wrapped *before* construction. The factory owns that
 * wrap and seeds the prior conversation history (D15) at the same time,
 * keeping the runner ignorant of both concerns.
 *
 * Hosts using `AgentFactory` / `AgentPool` can implement this interface
 * themselves to keep their construction pipeline; the bundled default is
 * {@see DefaultInstrumentedAgentFactory}.
 *
 * @api
 */
interface InstrumentedAgentFactory
{
    /**
     * Build a fresh per-run agent with `$recorder` wired into the LLM /
     * dispatch decorators and `$history` seeded as the prior conversation.
     *
     * @param list<Message> $history Reconstructed prior turns
     *                               (output of {@see MessageHistoryMapper}).
     *                               Must be all-or-nothing per turn so the
     *                               ReAct loop sees no orphan tool calls.
     */
    public function newInstance(ToolCallRecorder $recorder, array $history): OptionAwareStreamingAgentInterface;

    /**
     * Names of the server-side tools the agent knows how to dispatch.
     * Used by the runner to compute the lenient intersection with the
     * client's declared `tools[]` (D16) — unknown names are dropped
     * silently rather than failing the run.
     *
     * @return list<string>
     */
    public function knownToolNames(): array;
}
