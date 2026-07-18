<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;

use function http_build_query;
use function is_array;
use function json_decode;

/**
 * Real (non-canned) HTTP call to Packagist's public search API — unlike
 * the other pure-computation tools here, this one round-trips a live
 * third-party service, so calling it alongside the other `safe` tools
 * shows the parallel wave overlapping genuine network latency.
 *
 * ALPS marks `package_search` as `safe`, so the safeAndIdempotent policy
 * keeps it visible to the LLM and confirm-free, joining the parallel wave.
 * `ClientInterface` is DI-bound (AppModule) so tests swap in a fake and
 * never touch the real network (same pattern as the LLM client, D22).
 */
final class Package extends ResourceObject
{
    public function __construct(
        private readonly ClientInterface $httpClient,
    ) {}

    /**
     * @param string $query Search term for a Composer package name
     *
     * A network failure here (ClientExceptionInterface) is left unhandled
     * on purpose: bear/tool-use's Dispatcher catches every Throwable from a
     * tool call and feeds it back to the LLM as an error tool_result — the
     * same delegation the other #[Tool] resources in this app rely on.
     *
     * json_decode()'s `mixed` return is an unavoidable JSON-decoding
     * boundary — the same class of warning already tolerated (via
     * mago-baseline.toml) at every other json_decode() call site in this
     * codebase (e.g. src/Input/Coerce.php); is_array() below narrows it
     * before use.
     *
     * @mago-expect analysis:unhandled-thrown-type
     * @mago-expect analysis:mixed-assignment
     */
    #[Tool(name: 'package_search', description: 'Search Packagist for a PHP/Composer package by name', confirm: false)]
    public function onGet(string $query): static
    {
        $uri = 'https://packagist.org/search.json?' . http_build_query(['q' => $query, 'per_page' => 1]);
        $response = $this->httpClient->sendRequest(new Request('GET', $uri));
        $decoded = json_decode((string) $response->getBody(), true);

        $results = is_array($decoded) && is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
        $top = $results[0] ?? null;

        $this->body = is_array($top)
            ? [
                'query' => $query,
                'found' => true,
                'name' => $top['name'] ?? null,
                'description' => $top['description'] ?? null,
                'url' => $top['url'] ?? null,
                'downloads' => $top['downloads'] ?? null,
            ]
            : ['query' => $query, 'found' => false];

        return $this;
    }
}
