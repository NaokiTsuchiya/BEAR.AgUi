<?php

declare(strict_types=1);

/*
 * Prototype verification — the core unknowns, isolated.
 *
 *  (1) Does the 3-stage Generator pipe actually stream lazily?
 *        ToolUse runStream() -> AgUiAdapter::run() -> SseResponder::respond()
 *      We prove laziness by recording the exact interleaving of "agent produced
 *      event N" and "responder wrote frame N". If it streams, production and
 *      consumption interleave; if something buffers, all production happens
 *      before any write.
 *
 *  (2) Is the AgentEvent -> AG-UI event mapping correct, including the
 *      synthesized TEXT_MESSAGE_START/_END boundaries and RUN_STARTED/_FINISHED?
 *
 *  (3) Does INTERRUPT round-trip: adapter yields INTERRUPT, caller send()s a
 *      decision, and it reaches the underlying ToolUse generator?
 */

require __DIR__ . '/../autoload.php';

use BEAR\AgUi\Adapter\AgUiAdapter;
use BEAR\AgUi\Event\AgUiEventInterface;
use BEAR\AgUi\Sse\SseEncoder;
use BEAR\AgUi\Sse\SseResponder;
use BEAR\AgUi\Sse\SseSinkInterface;
use BEAR\ToolUse\Runtime\AgentEvent;

/* ---- a recording sink: captures frames + a global event-ordering log ---- */

final class RecordingSink implements SseSinkInterface
{
    /** @var list<string> */
    public array $frames = [];
    public int|null $status = null;

    /** @param array<int, string> $log shared ordering log */
    public function __construct(private array &$log)
    {
    }

    public function open(int $statusCode): void
    {
        $this->status = $statusCode;
    }

    public function write(string $frame): void
    {
        $this->frames[] = $frame;
        // strip "data: " + trailing \n\n for the human-readable log
        $json = trim(substr($frame, strlen('data: ')));
        $this->log[] = 'WROTE  ' . $json;
    }

    public function close(): void
    {
    }
}

/* ---- a fake ToolUse streaming agent: yields a realistic AgentEvent script ---- *
 * Mirrors OptionAwareStreamingAgentInterface::runStream() returning
 * Generator<int, AgentEvent, mixed, void>. We log each production to prove
 * interleaving, and we capture what send() delivers back (the confirm decision).
 */

/** @param array<int, string> $log */
function fakeAgentStream(array &$log, array &$received): Generator
{
    $log[] = 'AGENT  text_delta "Let me "';
    yield AgentEvent::textDelta('Let me ');

    $log[] = 'AGENT  text_delta "check that. "';
    yield AgentEvent::textDelta('check that. ');

    // a tool call requiring confirmation -> should become INTERRUPT
    $log[] = 'AGENT  confirmation_required(search)';
    $decision = yield AgentEvent::confirmationRequired(
        toolName: 'search',
        toolId: 'call-1',
        input: ['q' => 'weather'],
        message: 'Allow web search for "weather"?',
    );
    // ToolUse generators accept send(false) to cancel; record what we got.
    $received['decision'] = $decision;
    $log[] = 'AGENT  <- received decision: ' . var_export($decision, true);

    if ($decision === false) {
        $log[] = 'AGENT  (cancelled, no tool run)';
    } else {
        $log[] = 'AGENT  tool_start(search)';
        yield AgentEvent::toolStart('search');

        $log[] = 'AGENT  tool_result(search)';
        yield AgentEvent::toolResult('search');
    }

    // resume text in a NEW message block
    $log[] = 'AGENT  text_delta "Done."';
    yield AgentEvent::textDelta('Done.');

    $log[] = 'AGENT  completed';
    yield AgentEvent::completed('Let me check that. Done.');
}

/* ============================ RUN 1: approve ============================ */

echo "=================== RUN 1: user APPROVES the tool ===================\n";
$log = [];
$received = [];
$sink = new RecordingSink($log);
$responder = new SseResponder(new SseEncoder(), $sink);
$adapter = new AgUiAdapter(threadId: 'thread-1', runId: 'run-1');

$agentGen = fakeAgentStream($log, $received);
$aguiStream = $adapter->run($agentGen);

/*
 * We can't use SseResponder directly here because we must answer the INTERRUPT.
 * So we drive the adapter generator manually, mimicking what an HTTP layer does:
 * write each event; when INTERRUPT appears, send() the user's decision back.
 * This is exactly the loop SseResponder runs, plus the send() branch.
 */
$sink->open(200);
foreach (driveWithConfirm($aguiStream, approve: true, log: $log) as $event) {
    $sink->write((new SseEncoder())->encode($event));
}
$sink->close();

echo implode("\n", $log) . "\n\n";
assertEventOrder($sink->frames, [
    'RUN_STARTED',
    'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
    'INTERRUPT',
    'TOOL_CALL_START', 'TOOL_CALL_RESULT',
    'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
    'RUN_FINISHED',
]);
assert($received['decision'] === true, 'approve decision must reach the agent');
echo "RUN 1 OK: order correct, decision(true) reached agent.\n\n";

/* ============================ RUN 2: deny ============================ */

echo "=================== RUN 2: user DENIES the tool ===================\n";
$log = [];
$received = [];
$sink = new RecordingSink($log);
$adapter = new AgUiAdapter(threadId: 'thread-2', runId: 'run-2');
$agentGen = fakeAgentStream($log, $received);
$aguiStream = $adapter->run($agentGen);

$sink->open(200);
foreach (driveWithConfirm($aguiStream, approve: false, log: $log) as $event) {
    $sink->write((new SseEncoder())->encode($event));
}
$sink->close();

echo implode("\n", $log) . "\n\n";
assertEventOrder($sink->frames, [
    'RUN_STARTED',
    'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
    'INTERRUPT',
    // denied -> NO tool_start / tool_result
    'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
    'RUN_FINISHED',
]);
assert($received['decision'] === false, 'deny decision must reach the agent');
echo "RUN 2 OK: tool skipped, decision(false) reached agent.\n\n";

/* ============================ RUN 3: laziness ============================ */

echo "=================== RUN 3: interleaving (laziness) proof ===================\n";
$log = [];
$received = [];
$adapter = new AgUiAdapter(threadId: 'thread-3', runId: 'run-3');
$agentGen = fakeAgentStream($log, $received);
$aguiStream = $adapter->run($agentGen);
$enc = new SseEncoder();

$sink = new RecordingSink($log);
$sink->open(200);
foreach (driveWithConfirm($aguiStream, approve: true, log: $log) as $event) {
    $sink->write($enc->encode($event));
}
$sink->close();

// If lazy, an AGENT line for "Done." appears AFTER earlier WROTE lines.
$joined = implode("\n", $log);
$agentDoneIdx = indexOfLineContaining($log, 'AGENT  text_delta "Done."');
$firstWriteIdx = indexOfLineContaining($log, 'WROTE');
assert($firstWriteIdx !== null && $agentDoneIdx !== null, 'log markers present');
assert(
    $firstWriteIdx < $agentDoneIdx,
    'FAIL: all writes happened before late production -> not streaming (buffered)',
);
echo "RUN 3 OK: production and consumption interleave -> the pipe is lazy/streaming.\n";
echo "  (first WROTE at log#{$firstWriteIdx} precedes late AGENT 'Done.' at log#{$agentDoneIdx})\n\n";

echo "ALL CHECKS PASSED\n";

/* ============================ helpers ============================ */

/**
 * Drive the adapter generator, answering any INTERRUPT with $approve.
 * Yields every AG-UI event outward (so the caller can frame+write it).
 *
 * This is the reference for what the HTTP/SSE layer must do: it is
 * SseResponder's loop plus the INTERRUPT send() branch.
 *
 * @param Generator<int, AgUiEventInterface, bool|null, void> $stream
 * @param array<int, string> $log
 * @return Generator<int, AgUiEventInterface>
 */
function driveWithConfirm(Generator $stream, bool $approve, array &$log): Generator
{
    while ($stream->valid()) {
        $event = $stream->current();
        yield $event;

        if ($event->type() === 'INTERRUPT') {
            $log[] = 'HTTP   INTERRUPT seen -> send(' . var_export($approve, true) . ')';
            $stream->send($approve);

            continue;
        }

        $stream->next();
    }
}

/** @param list<string> $frames @param list<string> $expectedTypes */
function assertEventOrder(array $frames, array $expectedTypes): void
{
    $actual = [];
    foreach ($frames as $frame) {
        $json = trim(substr($frame, strlen('data: ')));
        /** @var array{type: string} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $actual[] = $decoded['type'];
    }

    if ($actual !== $expectedTypes) {
        echo "  EXPECTED: " . implode(', ', $expectedTypes) . "\n";
        echo "  ACTUAL:   " . implode(', ', $actual) . "\n";
        throw new RuntimeException('event order mismatch');
    }
}

/** @param array<int, string> $log */
function indexOfLineContaining(array $log, string $needle): int|null
{
    foreach ($log as $i => $line) {
        if (str_contains($line, $needle)) {
            return $i;
        }
    }

    return null;
}
