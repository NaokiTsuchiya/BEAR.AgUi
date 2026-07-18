<?php

declare(strict_types=1);

namespace Example\Bear\Provider;

use BEAR\ToolUse\Runtime\AlpsContextInputProcessor;
use BEAR\ToolUse\Runtime\AlpsToolPolicyInputProcessor;
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use Example\Bear\ToolUse\AlpsContextAsSystemPromptProcessor;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\ToolUse\InstrumentedAgentFactory;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use Override;
use Ray\Di\ProviderInterface;

/**
 * Assembles the AgUiRunner facade with the ALPS governance processors
 * (tasks-m3 T5, D27, ADR0004):
 *
 *  - safeAndIdempotent policy — `rot13_get`/`word_similarity_get`/
 *    `sun_info_get`/`package_search` (safe) and `reminder_put` (idempotent)
 *    pass, `message_post` (unsafe) is stripped from every LLM request;
 *  - AlpsContextInputProcessor, re-homed into the system prompt by
 *    {@see AlpsContextAsSystemPromptProcessor} — injects the profile's
 *    semantics as background context instead of a fake user turn the
 *    model would otherwise feel obliged to reply to.
 *
 * @implements ProviderInterface<AgUiRunner>
 */
final class AgUiRunnerProvider implements ProviderInterface
{
    public function __construct(
        private readonly InstrumentedAgentFactory $agentFactory,
        private readonly MessageHistoryMapper $historyMapper,
        private readonly AgUiAdapter $adapter,
        private readonly AlpsSemanticDictionary $dictionary,
    ) {}

    #[Override]
    public function get(): AgUiRunner
    {
        return new AgUiRunner($this->agentFactory, $this->historyMapper, $this->adapter, [
            AlpsToolPolicyInputProcessor::safeAndIdempotent($this->dictionary),
            new AlpsContextAsSystemPromptProcessor(new AlpsContextInputProcessor($this->dictionary)),
        ]);
    }
}
