<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

use function usleep;

/**
 * Read-only news headline lookup, exposed as the `news_get` tool — the
 * second `safe` plain tool (D29): calling it together with `weather_get`
 * in one turn is what makes the WaitGroup parallelism observable.
 *
 * Same simulated latency as Weather so the overlap is visible: parallel
 * wall-clock ≈ 200ms, sequential would be ≈ 400ms.
 */
final class News extends ResourceObject
{
    private const LATENCY_MICROSECONDS = 200_000;

    /** @param string $topic Topic keyword to search headlines for */
    #[Tool(name: 'news_get', description: 'Get the latest news headline for a topic', confirm: false)]
    public function onGet(string $topic): static
    {
        usleep(self::LATENCY_MICROSECONDS);
        $this->body = [
            'topic' => $topic,
            'headline' => 'BEAR.Sunday ships an AG-UI showcase',
            'source' => 'demo-wire',
        ];

        return $this;
    }
}
