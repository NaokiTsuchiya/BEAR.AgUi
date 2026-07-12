<?php

declare(strict_types=1);

namespace Example\Server;

use Example\Server\Tool\AskConfirmationTool;
use Example\Server\Tool\GetTimeTool;
use Example\Shared\OpenAiMessageMapper;
use Example\Shared\OpenAiStreamingLlmClient;
use Example\Shared\OpenAiToolMapper;
use NaokiTsuchiya\BEARAgUi\Adapter\AgUiAdapter;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\Sse\SseEncoder;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use NaokiTsuchiya\BEARAgUi\ToolUse\MessageHistoryMapper;
use NaokiTsuchiya\BEARAgUi\ToolUse\StreamingAgentFactory;
use OpenAI;
use Psr\Log\NullLogger;

use function getenv;
use function rtrim;

/**
 * Builds the example AG-UI server from environment variables (T5, D18):
 *
 *  - OPENAI_API_KEY   only meaningful against real OpenAI; the stub ignores
 *                     it, so an unset key falls back to a placeholder rather
 *                     than failing the build.
 *  - OPENAI_BASE_URL  default https://api.openai.com/v1 — point it at the
 *                     bundled stub (http://127.0.0.1:8081/v1) for a key-less
 *                     deterministic demo.
 *  - OPENAI_MODEL     default gpt-4o-mini.
 *
 * OpenAI::factory()->make() only assembles the client object — it performs
 * no I/O — so buildRunner() cannot fail on connection problems; a wrong key,
 * URL, or unreachable endpoint surfaces during the run as RUN_ERROR on the
 * open stream (D11). Per D23 the runner only generates events;
 * {@see buildResponder()} provides the SSE framing half the front controller
 * combines with a sink.
 */
final class Bootstrap
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    /** Minimal on purpose: enough to make a real model use the demo tools; the stub ignores it (D). */
    private const SYSTEM_PROMPT = 'You are a helpful assistant. Use the provided tools when relevant.';

    public static function buildRunner(): AgUiRunner
    {
        $apiKey = self::env('OPENAI_API_KEY', 'stub');
        $baseUrl = rtrim(self::env('OPENAI_BASE_URL', self::DEFAULT_BASE_URL), '/');
        $model = self::env('OPENAI_MODEL', self::DEFAULT_MODEL);

        $llm = new OpenAiStreamingLlmClient(
            OpenAI::factory()->withApiKey($apiKey)->withBaseUri($baseUrl)->make(),
            new OpenAiMessageMapper(),
            new OpenAiToolMapper(),
            $model,
        );

        $getTime = new GetTimeTool();

        return new AgUiRunner(
            new StreamingAgentFactory(
                $llm,
                new DemoDispatcher($getTime),
                [$getTime->definition(), (new AskConfirmationTool())->definition()],
                self::SYSTEM_PROMPT,
            ),
            new MessageHistoryMapper(),
            new AgUiAdapter(new NullLogger()),
            [],
        );
    }

    public static function buildResponder(): SseResponder
    {
        return new SseResponder(new SseEncoder());
    }

    /** An unset OR empty variable falls back — `FOO=` in a shell must not yield ''. */
    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
