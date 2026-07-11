<?php

/**
 * OpenAI-compatible stub LLM front controller (D21).
 *
 * Serves POST /v1/chat/completions ONLY; everything else is a 404. The
 * Authorization header is never inspected (any key is accepted). Run with:
 *
 *   php -S 127.0.0.1:8081 example/stub-llm/public/index.php
 */

declare(strict_types=1);

use Example\StubLlm\CannedConversation;
use Example\StubLlm\OpenAiSseWriter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if ($method !== 'POST' || $path !== '/v1/chat/completions') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo
        json_encode(
            [
                'error' => [
                    'message' => 'Not found. This stub serves POST /v1/chat/completions only.',
                    'type' => 'invalid_request_error',
                    'code' => 'not_found',
                ],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )
    ;

    return;
}

$decoded = json_decode((string) file_get_contents('php://input'), true);
/** @var array<string, mixed> $requestBody */
$requestBody = is_array($decoded) ? $decoded : [];

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$conversation = new CannedConversation(time());
OpenAiSseWriter::fromEnv()->write($conversation->respond($requestBody));
