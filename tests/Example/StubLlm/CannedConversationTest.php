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
use function iterator_to_array;
use function sprintf;

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
        $firstStart = self::requireArray(self::requireKey($starts, 0));
        static::assertSame('call_demo_1', self::requireKey($firstStart, 'id'));
        static::assertSame('function', self::requireKey($firstStart, 'type'));
        $startFunction = self::requireArray(self::requireKey($firstStart, 'function'));
        static::assertSame('get_time', self::requireKey($startFunction, 'name'));

        // Non-empty arguments fragments, in stream order: exactly two, concatenating to the full JSON.
        $arguments = array_map(self::functionArguments(...), self::toolCallFragments($chunks));
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
        $firstStart = self::requireArray(self::requireKey($starts, 0));
        $firstFunction = self::requireArray(self::requireKey($firstStart, 'function'));
        static::assertSame('weather_get', self::requireKey($firstFunction, 'name'));
        $secondStart = self::requireArray(self::requireKey($starts, 1));
        $secondFunction = self::requireArray(self::requireKey($secondStart, 'function'));
        static::assertSame('news_get', self::requireKey($secondFunction, 'name'));
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
        $firstStart = self::requireArray(self::requireKey($starts, 0));
        $function = self::requireArray(self::requireKey($firstStart, 'function'));
        static::assertSame('reminder_put', self::requireKey($function, 'name'));
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
            static::assertNotSame('', self::requireKey($chunk, 'id'));
            static::assertSame('chat.completion.chunk', self::requireKey($chunk, 'object'));
            static::assertSame(self::CREATED, self::requireKey($chunk, 'created'));
            static::assertSame($model, self::requireKey($chunk, 'model'));

            $choices = self::choicesOf($chunk);
            static::assertCount(1, $choices);
            $choice = self::requireArray(self::requireKey($choices, 0));
            static::assertSame(0, self::requireKey($choice, 'index'));
        }

        $lastChunk = self::requireArray(self::requireKey($chunks, array_key_last($chunks)));
        $lastChoice = self::firstChoice($lastChunk);
        static::assertEquals(new stdClass(), self::requireKey($lastChoice, 'delta'));
    }

    /**
     * Only the last chunk carries a finish_reason; all earlier ones are null.
     *
     * @param non-empty-list<array<string, mixed>> $chunks
     */
    private static function assertFinishedWith(string $reason, array $chunks): void
    {
        $reasons = array_map(self::finishReasonOf(...), $chunks);

        static::assertSame($reason, self::requireKey($reasons, array_key_last($reasons)));
        static::assertSame([null], array_values(array_unique(array_slice($reasons, 0, -1))));
    }

    /** @param array<array-key, mixed> $chunk */
    private static function finishReasonOf(array $chunk): string|null
    {
        return self::requireNullableString(self::requireKey(self::firstChoice($chunk), 'finish_reason'));
    }

    /**
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<array-key, mixed>> each chunk's delta, empty-object delta as []
     */
    private static function deltas(array $chunks): array
    {
        return array_map(self::deltaOf(...), $chunks);
    }

    /**
     * @param array<array-key, mixed> $chunk
     *
     * @return array<array-key, mixed>
     */
    private static function deltaOf(array $chunk): array
    {
        return self::requireArrayOrEmptyObject(self::requireKey(self::firstChoice($chunk), 'delta'));
    }

    /**
     * A `stdClass` delta means "empty object on the wire"; every other delta
     * must be an array.
     *
     * @return array<array-key, mixed>
     */
    private static function requireArrayOrEmptyObject(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return [];
        }

        self::assertIsArray($value);

        return $value;
    }

    /** @param list<array<string, mixed>> $chunks */
    private static function concatContent(array $chunks): string
    {
        $contents = array_map(self::contentTextOf(...), self::deltas($chunks));

        return implode('', $contents);
    }

    /** @param array<array-key, mixed> $delta */
    private static function contentTextOf(array $delta): string
    {
        if (!array_key_exists('content', $delta)) {
            return '';
        }

        return self::requireString($delta['content']);
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
        return array_values(array_filter(self::toolCallFragments($chunks), self::hasId(...)));
    }

    /** @param array<array-key, mixed> $fragment */
    private static function hasId(array $fragment): bool
    {
        return array_key_exists('id', $fragment);
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

            foreach (self::requireArrayList($delta['tool_calls']) as $toolCall) {
                $fragments[] = $toolCall;
            }
        }

        return $fragments;
    }

    /**
     * @param array<array-key, mixed> $chunk
     *
     * @return array<array-key, mixed>
     */
    private static function choicesOf(array $chunk): array
    {
        return self::requireArray(self::requireKey($chunk, 'choices'));
    }

    /**
     * @param array<array-key, mixed> $chunk
     *
     * @return array<array-key, mixed>
     */
    private static function firstChoice(array $chunk): array
    {
        return self::requireArray(self::requireKey(self::choicesOf($chunk), 0));
    }

    /** @param array<array-key, mixed> $fragment */
    private static function functionArguments(array $fragment): string
    {
        $function = self::requireArray(self::requireKey($fragment, 'function'));

        return self::requireString(self::requireKey($function, 'arguments'));
    }

    /** @return array<array-key, mixed> */
    private static function requireArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /** @return list<array<array-key, mixed>> */
    private static function requireArrayList(mixed $value): array
    {
        self::assertIsArray($value);

        return array_map(self::requireArray(...), array_values($value));
    }

    private static function requireString(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function requireNullableString(mixed $value): string|null
    {
        if ($value === null) {
            return null;
        }

        self::assertIsString($value);

        return $value;
    }

    /** @param array<array-key, mixed> $array */
    private static function requireKey(array $array, int|string $key): mixed
    {
        if (!array_key_exists($key, $array)) {
            self::fail(sprintf('Missing array key: %s', $key));
        }

        return $array[$key];
    }
}
