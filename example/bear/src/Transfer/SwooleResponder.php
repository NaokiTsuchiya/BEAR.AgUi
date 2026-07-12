<?php

declare(strict_types=1);

namespace Example\Bear\Transfer;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\TransferInterface;
use Swoole\Http\Response;

/**
 * Per-request responder carrying the Swoole response: the standard (JSON)
 * transfer path for every resource, and — via the public {@see $response}
 * — the raw handle Invocations::transfer() wraps in a SwooleSseSink for
 * the SSE branch (D25/D29).
 */
final class SwooleResponder implements TransferInterface
{
    public function __construct(
        public readonly Response $response,
    ) {}

    /** @param array<string, mixed> $server */
    public function __invoke(ResourceObject $ro, array $server): void
    {
        unset($server);

        $this->response->status($ro->code);
        foreach ($ro->headers as $name => $value) {
            $this->response->header($name, $value);
        }

        $this->response->header('Content-Type', 'application/json');
        $this->response->end($ro->toString());
    }
}
