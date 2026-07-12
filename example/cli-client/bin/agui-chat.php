<?php

/**
 * AG-UI CLI chat client (M4, D30).
 *
 * A plain HTTP+SSE consumer of the AG-UI protocol — no BEAR.AgUi library
 * type is used, only the wire shapes in docs/reference/ag-ui-protocol.md.
 * Points at the M3 BEAR Swoole app (example/bear/public/server.php) by
 * default.
 *
 * One-shot mode (message on argv, exits after the run):
 *
 *   php example/cli-client/bin/agui-chat.php "Weather in Tokyo and the news, please."
 *
 * REPL mode (no argv, reads stdin line by line until EOF):
 *
 *   php example/cli-client/bin/agui-chat.php
 *
 * Target server: AGUI_BASE_URL env (default http://127.0.0.1:8080).
 */

declare(strict_types=1);

use Example\CliClient\Cli;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

exit((new Cli())->run($argv));
