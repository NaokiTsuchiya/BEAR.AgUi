<?php

/**
 * BEAR showcase bootstrap on Swoole (tasks-m3 T9, D29, spike S-d).
 *
 * `WaitGroup` parallel tool dispatch needs a per-request coroutine context,
 * which `php -S` / classic SAPIs cannot provide — so the app runs on a
 * long-lived `Swoole\Http\Server`: the Injector is assembled once and
 * reused across requests, `onRequest` fires inside a coroutine, and
 * `Runtime::enableCoroutine()` hooks blocking I/O (the demo tools' usleep)
 * into the scheduler so parallel dispatch really overlaps.
 *
 * Routes (AgentCore convention, D18):
 *
 *   GET  /ping         health probe → 200 JSON
 *   POST /invocations  AG-UI run → SSE stream (400 JSON on parse failure)
 *   anything else      404 JSON
 *
 * Run with:
 *
 *   php example/bear/public/server.php          # listens on 127.0.0.1:8080
 *
 * Point OPENAI_BASE_URL at the bundled stub (http://127.0.0.1:8081/v1)
 * for a key-less demo, or at real OpenAI.
 */

declare(strict_types=1);

use BEAR\Resource\ResourceInterface;
use Example\Bear\Module\AppModule;
use Example\Bear\Transfer\SwooleResponder;
use Ray\Di\Injector;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Runtime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$hostEnv = getenv('AGUI_HOST');
$host = (string) ($hostEnv !== false && $hostEnv !== '' ? $hostEnv : '127.0.0.1');

$portEnv = getenv('AGUI_PORT');
$port = (int) ($portEnv !== false && $portEnv !== '' ? $portEnv : 8080);

Runtime::enableCoroutine();

$injector = new Injector(new AppModule(), dirname(__DIR__) . '/var/tmp');
$resource = $injector->getInstance(ResourceInterface::class);

$server = new Server($host, $port);
$server->set(['enable_coroutine' => true]);

$server->on('start', static function () use ($host, $port): void {
    echo "BEAR.AgUi showcase listening on http://{$host}:{$port}\n";
});

$server->on('request', static function (Request $request, Response $response) use ($resource): void {
    $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
    $path = (string) ($request->server['request_uri'] ?? '/');
    $responder = new SwooleResponder($response);

    if ($method === 'GET' && $path === '/ping') {
        $resource->get('page://self/ping')->transfer($responder, []);

        return;
    }

    if ($method === 'POST' && $path === '/invocations') {
        $resource->post('page://self/invocations', [
            'rawBody' => (string) $request->getContent(),
        ])->transfer($responder, []);

        return;
    }

    $response->status(404);
    $response->header('Content-Type', 'application/json');
    $response->end('{"code":"NOT_FOUND","message":"This server serves GET /ping and POST /invocations only."}');
});

$server->start();
