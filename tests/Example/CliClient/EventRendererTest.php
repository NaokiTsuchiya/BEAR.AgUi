<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\CliClient;

use Example\CliClient\EventRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;
use function str_repeat;

#[CoversClass(EventRenderer::class)]
final class EventRendererTest extends TestCase
{
    public function testRendersTextMessageContentDeltaVerbatim(): void
    {
        static::assertSame('hi there', $this->rendered([
            'type' => 'TEXT_MESSAGE_CONTENT',
            'messageId' => 'm-1',
            'delta' => 'hi there',
        ]));
    }

    public function testRendersToolCallStartWithName(): void
    {
        static::assertSame("\n[tool] weather_get ...\n", $this->rendered([
            'type' => 'TOOL_CALL_START',
            'toolCallId' => 'tc-1',
            'toolCallName' => 'weather_get',
        ]));
    }

    public function testRendersToolCallResultWithContent(): void
    {
        static::assertSame("[tool] tc-1 -> Sunny, 20C\n", $this->rendered([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'm-2',
            'toolCallId' => 'tc-1',
            'content' => 'Sunny, 20C',
        ]));
    }

    public function testSummarizesLongToolCallResultContent(): void
    {
        $longContent = str_repeat('x', 500);

        $output = $this->rendered(['type' => 'TOOL_CALL_RESULT', 'toolCallId' => 'tc-1', 'content' => $longContent]);

        static::assertStringNotContainsString($longContent, $output);
        static::assertStringContainsString('…', $output);
    }

    public function testRendersInterruptOutcomeWithResumeCaveat(): void
    {
        $output = $this->rendered([
            'type' => 'RUN_FINISHED',
            'threadId' => 't-1',
            'runId' => 'r-1',
            'outcome' => [
                'type' => 'interrupt',
                'interrupts' => [
                    [
                        'id' => 'i-1',
                        'reason' => 'tool_confirmation',
                        'message' => 'Confirm buying milk?',
                        'toolCallId' => 'tc-1',
                    ],
                ],
            ],
        ]);

        static::assertStringContainsString('Confirm buying milk?', $output);
        static::assertStringContainsString('再開できません', $output);
    }

    public function testRendersNothingForSuccessOutcome(): void
    {
        static::assertSame('', $this->rendered([
            'type' => 'RUN_FINISHED',
            'threadId' => 't-1',
            'runId' => 'r-1',
            'outcome' => ['type' => 'success'],
        ]));
    }

    public function testRendersRunError(): void
    {
        static::assertSame("\n[error] agent exploded\n", $this->rendered([
            'type' => 'RUN_ERROR',
            'message' => 'agent exploded',
        ]));
    }

    public function testRendersNothingForUnknownEventType(): void
    {
        static::assertSame('', $this->rendered(['type' => 'STATE_SNAPSHOT']));
    }

    /** @param array<string, mixed> $event */
    private function rendered(array $event): string
    {
        $renderer = new EventRenderer();

        ob_start();
        $renderer->render($event);

        return (string) ob_get_clean();
    }
}
