<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support\Http;

use OpenAI;
use OpenAI\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Builds a real openai-php Client wired to an in-process PSR-18 fake (D22).
 *
 * guzzlehttp/psr7 ships the PSR-17 factories, so php-http/discovery resolves
 * them natively and openai-php can build its PSR-7 request without any test
 * doubles. The stream handler simply forwards to the fake — openai-php only
 * auto-streams for real Guzzle/Symfony clients, so any other PSR-18 client
 * needs it for createStreamed.
 */
final class OpenAiClientBuilder
{
    public static function build(ClientInterface $httpClient, string $baseUri = 'http://stub.invalid/v1'): Client
    {
        return OpenAI::factory()
            ->withApiKey('sk-stub')
            ->withBaseUri($baseUri)
            ->withHttpClient($httpClient)
            ->withStreamHandler($httpClient->sendRequest(...))
            ->make();
    }
}
