<?php

declare(strict_types=1);

/*
 * Real HTTP SSE endpoint for the prototype (php -S).
 *
 *   POST /invocations  with a RunAgentInput JSON body
 *   -> text/event-stream of AG-UI events
 *
 * This exercises the actual PhpSapiSseSink (headers + echo + flush) and the
 * timed fake agent below sleeps between deltas so a curl client can SEE the
 * stream arrive incrementally rather than all at once.
 */

require __DIR__ . '/../autoload.php';

use BEAR\AgUi\Adapter\AgUiAdapter;
use BEAR\AgUi\Event\AgUiEventInterface;
use BEAR\AgUi\Input\RunAgentInput;
use BEAR\AgUi\Sse\PhpSapiSseSink;
use BEAR\AgUi\Sse\SseEncoder;
use BEAR\ToolUse\Runtime\AgentEvent;

/** A fake agent that pauses between tokens to make streaming observable. */
function timedAgentStream(string $userMessage): Generator
{
    $words = ['Processing ', 'your ', 'request: ', '"', $userMessage, '". ', 'Done.'];
    foreach ($words as $w) {
        usleep(200_000); // 200ms per token
        yield AgentEvent::textDelta($w);
    }

    yield AgentEvent::completed('Processing your request. Done.');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/ping') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'Healthy', 'time_of_last_update' => time()]);

    return;
}

if ($path !== '/invocations') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not found']);

    return;
}

$body = file_get_contents('php://input') ?: '';

try {
    $input = RunAgentInput::fromJson($body);
} catch (Throwable $e) {
    // connection-level validation failure -> HTTP 400 (ADR 0001)
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()]);

    return;
}

$adapter = new AgUiAdapter($input->threadId, $input->runId);
$agentStream = timedAgentStream($input->lastUserMessage());

$encoder = new SseEncoder();
$sink = new PhpSapiSseSink();
$sink->open(200);

/** @var AgUiEventInterface $event */
foreach ($adapter->run($agentStream) as $event) {
    $sink->write($encoder->encode($event));
}

$sink->close();
