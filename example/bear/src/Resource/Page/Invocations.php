<?php

declare(strict_types=1);

namespace Example\Bear\Resource\Page;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\TransferInterface;
use Example\Bear\Transfer\SwooleResponder;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Input\ParseError;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInputParser;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use NaokiTsuchiya\BEARAgUi\Sse\SwooleSseSink;
use Override;

use function array_map;
use function is_array;
use function is_iterable;

/**
 * AG-UI entry point as a plain ResourceObject (D25 — no dedicated Transfer
 * class, no attribute binding):
 *
 *  - onPost() owns the error dichotomy: the raw body goes through
 *    RunAgentInputParser::parse() — the single validation boundary (D23) —
 *    and parse failures become a 400 array body, while success puts the
 *    LAZY AgUiRunner::stream() generator into $body (nothing runs yet).
 *  - transfer() overrides delivery by body type: a generator body streams
 *    as SSE through the app-single SseResponder + a per-request
 *    SwooleSseSink built from the responder the server handed in (D29);
 *    array bodies (400) fall through to the standard JSON responder.
 *
 * Mid-run failures surface as RUN_ERROR frames on the open 200 stream
 * (D11); only parse failures can be rejected up front.
 */
final class Invocations extends ResourceObject
{
    public function __construct(
        private readonly RunAgentInputParser $parser,
        private readonly AgUiRunner $runner,
        private readonly SseResponder $sseResponder,
    ) {}

    public function onPost(string $rawBody): static
    {
        $result = $this->parser->parse($rawBody);
        if (!$result->isOk()) {
            $this->code = 400;
            $this->body = [
                'code' => 'VALIDATION_ERROR',
                'errors' => array_map(static fn(ParseError $error): array => [
                    'message' => $error->message,
                ], $result->unwrapErr()),
            ];

            return $this;
        }

        $this->body = $this->runner->stream($result->unwrap());

        return $this;
    }

    /** @param array<string, mixed> $server */
    #[Override]
    public function transfer(TransferInterface $responder, array $server): void
    {
        if ($responder instanceof SwooleResponder && is_iterable($this->body) && !is_array($this->body)) {
            /** @var iterable<AgUiEventInterface> $stream Placed by onPost — the only non-array body this resource produces. */
            $stream = $this->body;
            $this->sseResponder->respond($stream, new SwooleSseSink($responder->response));

            return;
        }

        parent::transfer($responder, $server);
    }
}
