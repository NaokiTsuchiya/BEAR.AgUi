<?php

declare(strict_types=1);

namespace Example\CliClient;

use CurlHandle;
use JsonException;
use RuntimeException;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * POSTs a `RunAgentInput` body to `{baseUrl}/invocations` and streams the
 * response with curl's `CURLOPT_WRITEFUNCTION`, decoding each SSE frame as
 * it arrives (not buffered — D30). Does not depend on any library type;
 * the request/response shapes come only from `docs/reference/ag-ui-protocol.md`.
 *
 * The two response shapes the server produces (ADR 0001 / D24) are told
 * apart by `Content-Type`: `text/event-stream` for a normal run,
 * `application/json` for a pre-stream 400 validation failure. Only the
 * curl wiring lives here; not exercised by an integration test (D22) —
 * verified by manual smoke against the M3 bear app instead.
 *
 * @mago-expect lint:cyclomatic-complexity
 *
 * The curl option wiring (two callbacks, each branching on the
 * Content-Type dichotomy) is what drives the count; splitting it further
 * would scatter one request's state across more classes for no benefit.
 */
final class AgUiHttpClient
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @param callable(array<string, mixed>): void $onEvent
     *
     * @throws RuntimeException When curl fails to initialize, or the request itself errors out.
     *
     * @mago-expect analysis:no-value
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * The CurlHandle parameter on the CURLOPT_HEADERFUNCTION / CURLOPT_WRITEFUNCTION
     * closures below is required by curl's callback signature but never read.
     *
     * curl_exec() drives CURLOPT_HEADERFUNCTION / CURLOPT_WRITEFUNCTION as
     * side effects that mutate $isJsonResponse / $rawJsonBody by
     * reference; mago's static flow analysis does not model mutation
     * through a curl callback, so it treats both as still holding their
     * initial value once curl_exec() returns and flags the branch below as
     * unreachable — it is not, as manual smoke against the M3 bear app
     * confirms.
     */
    public function stream(array $body, callable $onEvent): void
    {
        $reader = new SseFrameReader();
        $isJsonResponse = false;
        $rawJsonBody = '';

        $handle = curl_init($this->baseUrl . '/invocations');
        if (!$handle instanceof CurlHandle) {
            throw new RuntimeException('Failed to initialize curl handle.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/event-stream'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $_handle, string $headerLine) use (
                &$isJsonResponse,
            ): int {
                if (stripos($headerLine, 'content-type:') === 0) {
                    $value = strtolower(trim(substr($headerLine, strlen('content-type:'))));
                    $isJsonResponse = str_starts_with($value, 'application/json');
                }

                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $_handle, string $chunk) use (
                $reader,
                $onEvent,
                &$isJsonResponse,
                &$rawJsonBody,
            ): int {
                if ($isJsonResponse) {
                    $rawJsonBody .= $chunk;

                    return strlen($chunk);
                }

                foreach ($reader->feed($chunk) as $frame) {
                    $decoded = $reader->decode($frame);
                    if ($decoded !== null) {
                        $onEvent($decoded);
                    }
                }

                return strlen($chunk);
            },
        ]);

        curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);

        if ($errorNumber !== 0) {
            throw new RuntimeException('AG-UI request failed: ' . $errorMessage);
        }

        if ($rawJsonBody !== '') {
            $onEvent(['type' => 'RUN_ERROR', 'message' => self::describeValidationError($rawJsonBody)]);
        }
    }

    private static function describeValidationError(string $rawJsonBody): string
    {
        try {
            $decoded = json_decode($rawJsonBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $rawJsonBody;
        }

        if (!is_array($decoded) || !is_array($decoded['errors'] ?? null)) {
            return $rawJsonBody;
        }

        $messages = [];
        foreach ($decoded['errors'] as $error) {
            if (!is_array($error) || !is_string($error['message'] ?? null)) {
                continue;
            }

            $messages[] = $error['message'];
        }

        return $messages === [] ? $rawJsonBody : implode('; ', $messages);
    }
}
