<?php

/**
 * Example AG-UI server front controller (T6, ADR 0001).
 *
 * Routes follow the AgentCore convention (D18):
 *
 *   GET  /ping         health probe → 200 JSON
 *   POST /invocations  AG-UI run → SSE stream
 *   anything else      404 JSON
 *
 * Error dichotomy (D23/D24): connection-level failures — wrong Content-Type
 * (415) and input parse errors (400, all ParseErrors aggregated) — are
 * rejected BEFORE the stream opens. Once respond() starts, the stream is an
 * open 200 and failures are the library's concern (RUN_ERROR frames, D11),
 * so there is deliberately no try/catch around it.
 *
 * Run with:
 *
 *   php -S 127.0.0.1:8080 example/server/public/index.php
 */

declare(strict_types=1);

use Example\Server\Bootstrap;
use NaokiTsuchiya\BEARAgUi\Input\RunAgentInputParser;
use NaokiTsuchiya\BEARAgUi\Sse\PhpSapiSseSink;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @param array<string, mixed> $payload */
function respond_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if ($method === 'GET' && $path === '/ping') {
    respond_json(200, ['status' => 'Healthy', 'time_of_last_update' => time()]);

    return;
}

if ($method !== 'POST' || $path !== '/invocations') {
    respond_json(404, [
        'code' => 'NOT_FOUND',
        'message' => 'Not found. This server serves GET /ping and POST /invocations only.',
    ]);

    return;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$mediaType = strtolower(trim(explode(';', $contentType)[0]));
if ($mediaType !== 'application/json') {
    respond_json(415, [
        'code' => 'UNSUPPORTED_MEDIA_TYPE',
        'message' => 'Content-Type must be application/json.',
    ]);

    return;
}

$result = (new RunAgentInputParser())->parse((string) file_get_contents('php://input'));
$isOk = $result->isOk();
if (!$isOk) {
    /** @var list<array{message: string}> $errors */
    $errors = [];
    foreach ($result->unwrapErr() as $parseError) {
        $errors[] = ['message' => $parseError->message];
    }

    respond_json(400, [
        'code' => 'VALIDATION_ERROR',
        'errors' => $errors,
    ]);

    return;
}

Bootstrap::buildResponder()->respond(Bootstrap::buildRunner()->stream($result->unwrap()), new PhpSapiSseSink());
