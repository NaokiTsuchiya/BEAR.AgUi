<?php

declare(strict_types=1);

namespace Example\Bear\Resource\Page;

use BEAR\Resource\ResourceObject;

use function time;

/**
 * Health probe (AgentCore convention, D18): plain JSON through the
 * standard transfer path — never SSE.
 */
final class Ping extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [
            'status' => 'Healthy',
            'time_of_last_update' => time(),
        ];

        return $this;
    }
}
