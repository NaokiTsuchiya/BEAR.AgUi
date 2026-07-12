<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

use function usleep;

/**
 * Read-only weather lookup, exposed to the agent as the `weather_get` tool.
 *
 * ALPS marks `weather_get` as `safe`, so the safeAndIdempotent policy keeps
 * it visible to the LLM; being confirm-free it belongs to the parallel wave
 * and runs concurrently with `news_get` when both are called in one turn.
 *
 * The usleep simulates upstream latency: under Swoole with
 * `Runtime::enableCoroutine()` it suspends the coroutine, so the parallel
 * run finishes in ~1× latency instead of the sequential sum.
 */
final class Weather extends ResourceObject
{
    private const LATENCY_MICROSECONDS = 200_000;

    /** @param string $city City name to look up */
    #[Tool(name: 'weather_get', description: 'Get the current weather for a city', confirm: false)]
    public function onGet(string $city): static
    {
        usleep(self::LATENCY_MICROSECONDS);
        $this->body = [
            'city' => $city,
            'condition' => 'sunny',
            'temperature_c' => 23,
        ];

        return $this;
    }
}
