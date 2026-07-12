<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\StubLlm;

use Example\StubLlm\CannedConversation;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_column;
use function array_filter;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function implode;
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
        static::assertSame('get_time', $starts[0]['function']['name']);

        // Non-empty arguments fragments, in stream order: exactly two, concatenating to the full JSON.
        $arguments = array_column(array_column(self::toolCallFragments($chunks), 'function'), 'arguments');
        $fragments = array_values(array_filter($arguments, static fn(string $fragment): bool => $fragment !== ''));
        static::assertCount(2, $fragments);
        static::assertSame('{"timezone":"UTC"}', implode('', $fragments));

        self::assertFinishedWith('tool_calls', $chunks);
    }

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
        static::assertSame('weather_get', $starts[0]['function']['name']);
        static::assertSame('news_get', $starts[1]['function']['name']);
        self::assertFinishedWith('tool_calls', $chunks);
    }

    public function testRemindKeywordPlaysConfirmableReminderScenario(): void
    {
        $chunks = self::respond([
            'model' => 'gpt-test',
            'messages' => [['role' => 'user', 'content' => 'Remind me to buy milk.']],
        ]);

        $starts = self::toolCallStarts($chunks);
        static::assertCount(1, $starts);
        static::assertSame('reminder_put', $starts[0]['function']['name']);
        self::assertFinishedWith('tool_calls', $chunks);
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return list<array<string, mixed>>
     */
    private static function respond(array $requestBody): array
    {
        return iterator_to_array((new CannedConversation(self::CREATED))->respond($requestBody), false);
    }

    /**
     * Every chunk carries the exact chat.completion.chunk envelope, echoes the
     * request model, and the LAST chunk has an empty-object delta.
     *
     * @param list<array<string, mixed>> $chunks
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
            static::assertCount(1, $chunk['choices']);
            static::assertSame(0, $chunk['choices'][0]['index']);
        }

        static::assertEquals(new stdClass(), $chunks[array_key_last($chunks)]['choices'][0]['delta']);
    }

    /**
     * Only the last chunk carries a finish_reason; all earlier ones are null.
     *
     * @param non-empty-list<array<string, mixed>> $chunks
     */
    private static function assertFinishedWith(string $reason, array $chunks): void
    {
        $reasons = array_map(static fn(array $chunk): string|null => $chunk['choices'][0]['finish_reason'], $chunks);

        static::assertSame($reason, $reasons[array_key_last($reasons)]);
        static::assertSame([null], array_values(array_unique(array_slice($reasons, 0, -1))));
    }

    /**
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<string, mixed>> each chunk's delta, empty-object delta as []
     */
    private static function deltas(array $chunks): array
    {
        return array_map(static fn(array $chunk): array => (array) $chunk['choices'][0]['delta'], $chunks);
    }

    /** @param list<array<string, mixed>> $chunks */
    private static function concatContent(array $chunks): string
    {
        return implode('', array_column(self::deltas($chunks), 'content'));
    }

    /**
     * Tool-call fragments that open a call (carry an id).
     *
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<string, mixed>>
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
     * @return list<array<string, mixed>>
     */
    private static function toolCallFragments(array $chunks): array
    {
        return array_merge([], ...array_column(self::deltas($chunks), 'tool_calls'));
    }
}
