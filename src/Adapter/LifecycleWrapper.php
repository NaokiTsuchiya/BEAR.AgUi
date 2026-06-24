<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Adapter;

use Generator;
use NaokiTsuchiya\BEARAgUi\Event\AgUiEventInterface;
use NaokiTsuchiya\BEARAgUi\Event\RunError;
use NaokiTsuchiya\BEARAgUi\Event\RunFinished;
use NaokiTsuchiya\BEARAgUi\Event\RunStarted;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wraps an inner AG-UI event stream with the run-lifecycle boundary events.
 *
 * Pre-conditions on the inner stream:
 *  - emits zero or more non-terminal AG-UI events, optionally followed by a
 *    single terminal event ({@see RunFinished} or {@see RunError}).
 *
 * Post-conditions on the wrapped output:
 *  - first event is always {@see RunStarted};
 *  - if the inner stream raises a {@see Throwable}, the wrapper logs the
 *    exception via the optional logger and yields a generic {@see RunError};
 *  - if the inner stream produced no terminal event, the wrapper appends
 *    {@see RunFinished::success()}.
 *
 * Holds no per-run state of its own — every method-local variable lives in
 * the generator's stack frame.
 *
 * @internal
 */
final class LifecycleWrapper
{
    public function __construct(
        private readonly string $threadId,
        private readonly string $runId,
        private readonly LoggerInterface|null $logger,
    ) {}

    /**
     * @param Generator<int, AgUiEventInterface, mixed, void> $inner
     *
     * @return Generator<int, AgUiEventInterface, mixed, void>
     */
    public function wrap(Generator $inner): Generator
    {
        yield new RunStarted($this->threadId, $this->runId);

        try {
            $sawTerminal = false;
            foreach ($inner as $event) {
                yield $event;
                if ($event instanceof RunFinished || $event instanceof RunError) {
                    $sawTerminal = true;
                    return;
                }
            }

            if (!$sawTerminal) {
                yield RunFinished::success($this->threadId, $this->runId);
            }
        } catch (Throwable $e) {
            $this->logger?->error('AgUiAdapter caught throwable while consuming agent stream: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            yield new RunError('Internal agent error.', 'AGENT_ERROR');
        }
    }
}
