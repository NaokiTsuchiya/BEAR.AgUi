<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use Closure;
use GuzzleHttp\Psr7\Response;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * PSR-18 fake that answers OpenAI chat requests in-process (D22).
 *
 * Decodes the JSON request body, hands it to the injected chunk producer
 * (typically Example\StubLlm\CannedConversation::respond(...)), and returns
 * the produced chat.completion.chunk payloads as a canned text/event-stream
 * response in the exact framing openai-php's StreamResponse parses: one
 * `data: {json}` line per chunk, terminated by `data: [DONE]`. Every request
 * is recorded for write-side assertions.
 */
final class StubHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param Closure(array<string, mixed>): iterable<int, array<string, mixed>> $chunkProducer */
    public function __construct(
        private readonly Closure $chunkProducer,
    ) {}

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        /** @var array<string, mixed> $requestBody */
        $requestBody = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $body = '';
        foreach (($this->chunkProducer)($requestBody) as $chunk) {
            $json = json_encode($chunk, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body .= 'data: ' . $json . "\n\n";
        }

        $body .= "data: [DONE]\n\n";

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }
}
