<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\StubLlm;

use Example\StubLlm\CannedConversation;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function array_filter;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function implode;
use function is_string;
use function iterator_to_array;

/**
 * Pure unit tests for the stub's canned OpenAI conversation (D21). No CoversClass:
 * example/ classes are outside the coverage include path (like the vendor-facing
 * contract tests in tests/Integration).
 *
 * @mago-expect lint:too-many-methods
 *
 * One method per scenario/turn contract plus focused chunk-shape helpers;
 * merging them would obscure which OpenAI wire contract is failing.
 */
final class CannedConversationTest extends TestCase
{
    private const CREATED = 1_751_900_000;

    /** @throws RuntimeException */
    public function testFirstTurnStreamsTextThenToolCallEndingInToolCalls(): void
    {
        $chunks = self::respond([
            'model' => 'gpt-test',
            'messages' => [['role' => 'user', 'content' => 'What time is it?']],
        ]);

        self::assertEnvelope($chunks, 'gpt-test');
        static::assertNotSame('', self::concatContent($chunks));

        $starts = self::toolCallStarts($chunks);
        static::assertCount(1, $starts);
        static::assertSame('call_demo_1', $starts[0]['id']);
        static::assertSame('function', $starts[0]['type']);
        $startFunction = $starts[0]['function'];
        static::assertIsArray($startFunction);
        static::assertSame('get_time', $startFunction['name']);

        // Non-empty arguments fragments, in stream order: exactly two, concatenating to the full JSON.
        $arguments = array_map(static function (array $fragment): string {
            $function = $fragment['function'];
            self::assertIsArray($function);
            $fragmentArguments = $function['arguments'];
            self::assertIsString($fragmentArguments);

            return $fragmentArguments;
        }, self::toolCallFragments($chunks));
        $fragments = array_values(array_filter($arguments, static fn(string $fragment): bool => $fragment !== ''));
        static::assertCount(2, $fragments);
        static::assertSame('{"timezone":"UTC"}', implode('', $fragments));

        self::assertFinishedWith('tool_calls', $chunks);
    }

    /** @throws RuntimeException */
    public function testSecondTurnEchoesReceivedToolContentAndStops(): void
    {
        $toolContent = '2026-07-08T12:34:56+00:00';
        $chunks = self::respond([
            'model' => 'gpt-test',
            'messages' => [
                ['role' => 'user', 'content' => 'What time is it?'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => []],
                ['role' => 'tool', 'tool_call_id' => 'call_demo_1', 'content' => $toolContent],
            ],
        ]);

        self::assertEnvelope($chunks, 'gpt-test');
        static::assertStringContainsString($toolContent, self::concatContent($chunks));
        static::assertSame([], self::toolCallFragments($chunks));
        self::assertFinishedWith('stop', $chunks);
    }

    /** @throws RuntimeException */
    public function testTurnDetectionUsesTheLastMessageRoleOnly(): void
    {
        // A tool message earlier in history must NOT trigger the final turn.
        $chunks = self::respond([
            'model' => 'gpt-test',
            'messages' => [
                ['role' => 'tool', 'tool_call_id' => 'call_old', 'content' => 'stale'],
                ['role' => 'user', 'content' => 'Again please.'],
            ],
        ]);

        self::assertFinishedWith('tool_calls', $chunks);
    }

    /** @throws RuntimeException */
    public function testWeatherKeywordPlaysParallelToolScenario(): void
    {
        // The ALPS context message the M3 app appends lists every tool name;
        // scenario detection must skip it and read the human trigger.
        $chunks = self::respond([
            'model' => 'gpt-test',
            'messages' => [
                ['role' => 'user', 'content' => 'Weather in Tokyo and the news, please.'],
                ['role' => 'user', 'content' => "Application semantics from ALPS:\n- reminder_put [idempotent]: x"],
            ],
        ]);

        $starts = self::toolCallStarts($chunks);
        static::assertCount(2, $starts);
        $firstFunction = $starts[0]['function'];
        static::assertIsArray($firstFunction);
        static::assertSame('weather_get', $firstFunction['name']);
        $secondFunction = $starts[1]['function'];
        static::assertIsArray($secondFunction);
        static::assertSame('news_get', $secondFunction['name']);
        self::assertFinishedWith('tool_calls', $chunks);
    }

    /** @throws RuntimeException */
    public function testRemindKeywordPlaysConfirmableReminderScenario(): void
    {
        $chunks = self::respond([
            'model' => 'gpt-test',
            'messages' => [['role' => 'user', 'content' => 'Remind me to buy milk.']],
        ]);

        $starts = self::toolCallStarts($chunks);
        static::assertCount(1, $starts);
        $function = $starts[0]['function'];
        static::assertIsArray($function);
        static::assertSame('reminder_put', $function['name']);
        self::assertFinishedWith('tool_calls', $chunks);
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return non-empty-list<array<string, mixed>>
     *
     * @throws RuntimeException if the stub yields no chunks (never happens; documents the invariant).
     */
    private static function respond(array $requestBody): array
    {
        $chunks = iterator_to_array((new CannedConversation(self::CREATED))->respond($requestBody), false);
        self::assertNotEmpty($chunks);
        if ($chunks === []) {
            throw new RuntimeException('CannedConversation::respond() must yield at least one chunk.');
        }

        return $chunks;
    }

    /**
     * Every chunk carries the exact chat.completion.chunk envelope, echoes the
     * request model, and the LAST chunk has an empty-object delta.
     *
     * @param non-empty-list<array<string, mixed>> $chunks
     */
    private static function assertEnvelope(array $chunks, string $model): void
    {
        static::assertNotSame([], $chunks);
        foreach ($chunks as $chunk) {
            static::assertSame(['id', 'object', 'created', 'model', 'choices'], array_keys($chunk));
            static::assertNotSame('', $chunk['id']);
            static::assertSame('chat.completion.chunk', $chunk['object']);
            static::assertSame(self::CREATED, $chunk['created']);
            static::assertSame($model, $chunk['model']);

            $choices = $chunk['choices'];
            self::assertIsArray($choices);
            static::assertCount(1, $choices);

            $choice = $choices[0];
            self::assertIsArray($choice);
            static::assertSame(0, $choice['index']);
        }

        $lastChunk = $chunks[array_key_last($chunks)];
        $lastChoices = $lastChunk['choices'];
        self::assertIsArray($lastChoices);
        $lastChoice = $lastChoices[0];
        self::assertIsArray($lastChoice);
        static::assertEquals(new stdClass(), $lastChoice['delta']);
    }

    /**
     * Only the last chunk carries a finish_reason; all earlier ones are null.
     *
     * @param non-empty-list<array<string, mixed>> $chunks
     */
    private static function assertFinishedWith(string $reason, array $chunks): void
    {
        $reasons = array_map(static function (array $chunk): string|null {
            $choices = $chunk['choices'];
            self::assertIsArray($choices);
            $choice = $choices[0];
            self::assertIsArray($choice);
            $finishReason = $choice['finish_reason'];
            self::assertTrue($finishReason === null || is_string($finishReason));

            return $finishReason;
        }, $chunks);

        static::assertSame($reason, $reasons[array_key_last($reasons)]);
        static::assertSame([null], array_values(array_unique(array_slice($reasons, 0, -1))));
    }

    /**
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<array-key, mixed>> each chunk's delta, empty-object delta as []
     */
    private static function deltas(array $chunks): array
    {
        return array_map(static function (array $chunk): array {
            $choices = $chunk['choices'];
            self::assertIsArray($choices);
            $choice = $choices[0];
            self::assertIsArray($choice);
            $delta = $choice['delta'];

            if ($delta instanceof stdClass) {
                return [];
            }

            self::assertIsArray($delta);

            return $delta;
        }, $chunks);
    }

    /** @param list<array<string, mixed>> $chunks */
    private static function concatContent(array $chunks): string
    {
        $contents = array_map(static function (array $delta): string {
            if (!array_key_exists('content', $delta)) {
                return '';
            }

            $content = $delta['content'];
            self::assertIsString($content);

            return $content;
        }, self::deltas($chunks));

        return implode('', $contents);
    }

    /**
     * Tool-call fragments that open a call (carry an id).
     *
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<array-key, mixed>>
     */
    private static function toolCallStarts(array $chunks): array
    {
        return array_values(array_filter(
            self::toolCallFragments($chunks),
            static fn(array $fragment): bool => array_key_exists('id', $fragment),
        ));
    }

    /**
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<array-key, mixed>>
     */
    private static function toolCallFragments(array $chunks): array
    {
        $fragments = [];
        foreach (self::deltas($chunks) as $delta) {
            if (!array_key_exists('tool_calls', $delta)) {
                continue;
            }

            $toolCalls = $delta['tool_calls'];
            self::assertIsArray($toolCalls);
            foreach ($toolCalls as $toolCall) {
                self::assertIsArray($toolCall);
                $fragments[] = $toolCall;
            }
        }

        return $fragments;
    }
}
