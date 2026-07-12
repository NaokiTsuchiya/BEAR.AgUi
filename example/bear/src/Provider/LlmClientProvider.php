<?php

declare(strict_types=1);

namespace Example\Bear\Provider;

use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use Example\Shared\OpenAiMessageMapper;
use Example\Shared\OpenAiStreamingLlmClient;
use Example\Shared\OpenAiToolMapper;
use OpenAI;
use Override;
use Ray\Di\ProviderInterface;

use function getenv;
use function rtrim;

/**
 * Builds the real LLM client from environment variables, same contract as
 * the M2 example (D18): point OPENAI_BASE_URL at the bundled stub
 * (http://127.0.0.1:8081/v1) for a key-less deterministic demo, or at real
 * OpenAI. Client assembly performs no I/O — connection failures surface
 * mid-run as RUN_ERROR (D11).
 *
 * @implements ProviderInterface<StreamingLlmClientInterface>
 */
final class LlmClientProvider implements ProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    #[Override]
    public function get(): StreamingLlmClientInterface
    {
        return new OpenAiStreamingLlmClient(
            OpenAI::factory()
                ->withApiKey(self::env('OPENAI_API_KEY', 'stub'))
                ->withBaseUri(rtrim(self::env('OPENAI_BASE_URL', self::DEFAULT_BASE_URL), '/'))
                ->make(),
            new OpenAiMessageMapper(),
            new OpenAiToolMapper(),
            self::env('OPENAI_MODEL', self::DEFAULT_MODEL),
        );
    }

    /** An unset OR empty variable falls back — `FOO=` in a shell must not yield ''. */
    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
