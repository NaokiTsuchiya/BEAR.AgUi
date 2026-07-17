<?php

declare(strict_types=1);

namespace Example\Bear\ToolUse;

use BEAR\ToolUse\Runtime\AlpsContextInputProcessor;
use BEAR\ToolUse\Runtime\InputProcessorInterface;
use BEAR\ToolUse\Runtime\LlmRequest;
use Override;

use function array_pop;
use function count;
use function is_string;

/**
 * Wraps {@see AlpsContextInputProcessor} (final in bear/tool-use, so
 * composition rather than inheritance) to move its output from a fake
 * `{role: user}` turn into the system prompt.
 *
 * The wrapped processor appends the tool/parameter semantics as a plain
 * user message on every LLM call — including the follow-up call right
 * after tool results, where it lands as the LAST message the model sees.
 * Models read that position as "what the user just said" and reply to it
 * (e.g. an unprompted "ALPSの定義を確認しました" tacked onto an otherwise
 * correct tool-result answer) instead of treating it as background
 * context. Re-homing the same text into `systemPrompt` keeps the
 * governance content (ALPS-derived tool/parameter descriptions) but drops
 * the appearance of a new conversational turn.
 */
final readonly class AlpsContextAsSystemPromptProcessor implements InputProcessorInterface
{
    public function __construct(
        private AlpsContextInputProcessor $inner,
    ) {}

    /**
     * `content[0]['text']` is read from `Message`'s untyped
     * `array<string, mixed>` content block; is_string() below narrows it
     * before use (same pattern as example/bear/src/Logger/StdoutLogger.php).
     *
     * @mago-expect analysis:mixed-assignment
     */
    #[Override]
    public function process(LlmRequest $request): LlmRequest
    {
        $processed = $this->inner->process($request);
        if (count($processed->messages) === count($request->messages)) {
            // No ALPS descriptor matched any tool/parameter in this
            // request — the wrapped processor left $request untouched.
            return $request;
        }

        // The wrapped processor only ever appends one trailing message
        // (see AlpsContextInputProcessor::process()), so processed is
        // guaranteed non-empty here.
        $messages = $processed->messages;
        $context = array_pop($messages);
        $rawText = $context->content[0]['text'] ?? '';
        $text = is_string($rawText) ? $rawText : '';

        return $request->withSystemPrompt(
            $request->systemPrompt === '' ? $text : $request->systemPrompt . "\n\n" . $text,
        );
    }
}
