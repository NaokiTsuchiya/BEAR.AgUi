<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

/**
 * Mutable state passed through the {@see AgentEventTranslator} dispatch
 * loop. Lives only for the duration of one run; never shared.
 *
 * @internal
 */
final class TranslationState
{
    public string|null $openMessageId = null;

    /** @var array<string, list<string>> tool name → FIFO of tool-call ids awaiting a tool_result event. */
    public array $awaitingResult = [];

    /** Set true once a terminal RunFinished{interrupt} or RunError was yielded. */
    public bool $terminated = false;
}
