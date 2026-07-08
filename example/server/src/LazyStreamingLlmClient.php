<?php

declare(strict_types=1);

namespace Example\Server;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use Closure;
use Generator;
use Override;

/**
 * Defers construction of the underlying streaming LLM client to the first
 * chatStream() call.
 *
 * openai-php's Factory::make() runs PSR-18 discovery, which throws when no
 * HTTP client implementation is installed. Deferring it keeps
 * {@see Bootstrap::buildRunner()} total: GET /ping never builds an HTTP
 * client, and a misconfigured LLM connection fails inside the run — where
 * the adapter maps it to RUN_ERROR on the already-open 200 stream (D11) —
 * instead of crashing the front controller before the stream starts.
 */
final class LazyStreamingLlmClient implements StreamingLlmClientInterface
{
    private StreamingLlmClientInterface|null $client = null;

    /** @param Closure(): StreamingLlmClientInterface $factory */
    public function __construct(
        private readonly Closure $factory,
    ) {}

    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    #[Override]
    public function chatStream(string $system, array $messages, array $tools): Generator
    {
        $this->client ??= ($this->factory)();

        yield from $this->client->chatStream($system, $messages, $tools);
    }
}
