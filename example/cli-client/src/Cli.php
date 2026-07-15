<?php

declare(strict_types=1);

namespace Example\CliClient;

use Random\RandomException;
use RuntimeException;

use function array_slice;
use function bin2hex;
use function fgets;
use function getenv;
use function implode;
use function is_string;
use function random_bytes;
use function trim;
use function uniqid;

use const STDIN;

/**
 * Orchestrates the chat loop: reads user input, drives one AG-UI run per
 * turn, and wires its events into both the renderer (terminal output) and
 * the conversation log (history to resend on the next run).
 *
 * `threadId` is generated once per process (a session); `runId` is
 * generated fresh per turn — the client owns both (M1 division of
 * responsibility; the server only validates). Two entry modes: a message
 * given on argv runs once and exits (scripting / manual smoke), otherwise
 * this reads one line at a time from stdin until EOF (interactive REPL).
 */
final class Cli
{
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:8080';

    /**
     * @param list<string> $argv
     *
     * @throws RuntimeException Propagated from {@see AgUiHttpClient::stream()} on a transport failure.
     */
    public function run(array $argv): int
    {
        $client = new AgUiHttpClient(self::baseUrl());
        $log = new ConversationLog();
        $renderer = new EventRenderer();
        $threadId = self::newId();

        $message = trim(implode(' ', array_slice($argv, 1)));
        if ($message !== '') {
            $this->runTurn($client, $log, $renderer, $threadId, $message);

            return 0;
        }

        $line = fgets(STDIN);
        while ($line !== false) {
            $text = trim($line);
            if ($text !== '') {
                $this->runTurn($client, $log, $renderer, $threadId, $text);
            }

            $line = fgets(STDIN);
        }

        return 0;
    }

    /** @throws RuntimeException Propagated from {@see AgUiHttpClient::stream()} on a transport failure. */
    private function runTurn(
        AgUiHttpClient $client,
        ConversationLog $log,
        EventRenderer $renderer,
        string $threadId,
        string $text,
    ): void {
        $log->appendUser($text);

        $body = [
            'threadId' => $threadId,
            'runId' => self::newId(),
            'messages' => $log->toMessages(),
        ];

        $client->stream(
            $body,
            /** @param array<string, mixed> $event */
            static function (array $event) use ($renderer, $log): void {
                $renderer->render($event);
                $log->observe($event);
            },
        );

        echo "\n";
    }

    private static function baseUrl(): string
    {
        $value = getenv('AGUI_BASE_URL');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_BASE_URL;
    }

    private static function newId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (RandomException) {
            return uniqid('id-', true);
        }
    }
}
