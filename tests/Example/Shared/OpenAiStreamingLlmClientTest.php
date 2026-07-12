<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Shared;

use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use Example\Shared\OpenAiMessageMapper;
use Example\Shared\OpenAiStreamingLlmClient;
use Example\Shared\OpenAiToolMapper;
use Example\StubLlm\CannedConversation;
use NaokiTsuchiya\BEARAgUi\Support\OpenAiClientBuilder;
use NaokiTsuchiya\BEARAgUi\Support\StubHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function array_key_last;
use function array_map;
use function iterator_to_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * OpenAI delta -> bear StreamEvent state machine tests (T3-c, D19/D22).
 *
 * No real HTTP: a PSR-18 fake (StubHttpClient) is injected through the
 * openai-php factory, so every test still runs the SDK's real request
 * building and SSE parsing path. The happy-path tests reuse the stub
 * server's CannedConversation as the single source of chunk shapes; the
 * mapping-branch tests script their own chunks.
 *
 * No CoversClass: example/ classes are outside the coverage include path.
 *
 * @mago-expect lint:too-many-methods
 *
 * One method per state-machine scenario / mapping branch is intentional,
 * same convention as CannedConversationTest; merging via data providers
 * would obscure which OpenAI wire contract is failing.
 */
final class OpenAiStreamingLlmClientTest extends TestCase
{
    private const CREATED = 1_751_900_000;

    public function testCannedToolCallTurnStreamsTextThenToolUse(): void
    {
        $http = self::cannedHttp();

        $events = self::pairs(self::stream(
            $http,
            'You are helpful.',
            [Message::user('What time is it?')],
            [self::getTimeTool()],
        ));

        static::assertSame(
            [
                [StreamEvent::TEXT_DELTA, ['text' => 'Let me check ']],
                [StreamEvent::TEXT_DELTA, ['text' => 'the current time.']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::TOOL_USE_START, ['id' => 'call_demo_1', 'name' => 'get_time']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => '{"timezone"']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => ':"UTC"}']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']],
            ],
            $events,
        );
    }

    public function testCannedToolResultTurnEchoesToolContentAndEndsTurn(): void
    {
        $http = self::cannedHttp();
        $messages = [
            Message::user('What time is it?'),
            Message::assistant([
                ['type' => 'tool_use', 'id' => 'call_demo_1', 'name' => 'get_time', 'input' => ['timezone' => 'UTC']],
            ]),
            Message::toolResults([ToolResult::success('call_demo_1', '12:34 UTC')]),
        ];

        $events = self::pairs(self::stream($http, 'You are helpful.', $messages, [self::getTimeTool()]));

        static::assertSame(
            [
                [StreamEvent::TEXT_DELTA, ['text' => 'The current time is ']],
                [StreamEvent::TEXT_DELTA, ['text' => '12:34 UTC']],
                [StreamEvent::TEXT_DELTA, ['text' => '.']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']],
            ],
            $events,
        );
    }

    public function testSendsMappedModelMessagesAndToolsInRequestBody(): void
    {
        $http = self::cannedHttp();

        self::stream($http, 'You are helpful.', [Message::user('What time is it?')], [self::getTimeTool()]);

        $sent = json_decode((string) $http->requests[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        static::assertSame('stub-model', $sent['model']);
        static::assertTrue($sent['stream']);
        static::assertSame(
            [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'What time is it?'],
            ],
            $sent['messages'],
        );
        static::assertSame(
            [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_time',
                        'description' => 'Returns the current time.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => ['timezone' => ['type' => 'string']],
                            'required' => [],
                        ],
                    ],
                ],
            ],
            $sent['tools'],
        );
    }

    public function testTextOnlyStreamStopsWithEndTurn(): void
    {
        $http = self::scriptedHttp([
            self::chunk(['role' => 'assistant', 'content' => '']),
            self::chunk(['content' => 'Hello']),
            self::chunk(['content' => ' world']),
            self::chunk([], 'stop'),
        ]);

        $events = self::pairs(self::stream($http, '', [Message::user('hi')], []));

        static::assertSame(
            [
                [StreamEvent::TEXT_DELTA, ['text' => 'Hello']],
                [StreamEvent::TEXT_DELTA, ['text' => ' world']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']],
            ],
            $events,
        );
    }

    public function testSingleToolWithSplitArgumentsStreamsOneToolBlock(): void
    {
        $http = self::scriptedHttp([
            self::chunk(['role' => 'assistant']),
            self::chunk(['tool_calls' => [self::toolCallStart(0, 'call_1', 'get_time')]]),
            self::chunk(['tool_calls' => [self::toolCallArguments(0, '{"timezone"')]]),
            self::chunk(['tool_calls' => [self::toolCallArguments(0, ':"UTC"}')]]),
            self::chunk([], 'tool_calls'),
        ]);

        $events = self::pairs(self::stream($http, '', [Message::user('time?')], [self::getTimeTool()]));

        static::assertSame(
            [
                [StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'get_time']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => '{"timezone"']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => ':"UTC"}']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']],
            ],
            $events,
        );
    }

    public function testTextToToolBoundaryClosesTextBlock(): void
    {
        $http = self::scriptedHttp([
            self::chunk(['content' => 'Checking.']),
            self::chunk(['tool_calls' => [self::toolCallStart(0, 'call_1', 'get_time')]]),
            self::chunk(['tool_calls' => [self::toolCallArguments(0, '{}')]]),
            self::chunk([], 'tool_calls'),
        ]);

        $events = self::pairs(self::stream($http, '', [Message::user('time?')], [self::getTimeTool()]));

        static::assertSame(
            [
                [StreamEvent::TEXT_DELTA, ['text' => 'Checking.']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'get_time']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => '{}']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']],
            ],
            $events,
        );
    }

    public function testToolToTextBoundaryClosesToolBlock(): void
    {
        $http = self::scriptedHttp([
            self::chunk(['tool_calls' => [self::toolCallStart(0, 'call_1', 'get_time')]]),
            self::chunk(['tool_calls' => [self::toolCallArguments(0, '{}')]]),
            self::chunk(['content' => 'And the answer is:']),
            self::chunk([], 'stop'),
        ]);

        $events = self::pairs(self::stream($http, '', [Message::user('time?')], [self::getTimeTool()]));

        static::assertSame(
            [
                [StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'get_time']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => '{}']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::TEXT_DELTA, ['text' => 'And the answer is:']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']],
            ],
            $events,
        );
    }

    public function testToolToToolBoundaryClosesFirstToolBlock(): void
    {
        $http = self::scriptedHttp([
            self::chunk(['role' => 'assistant']),
            self::chunk(['tool_calls' => [self::toolCallStart(0, 'call_1', 'get_time')]]),
            self::chunk(['tool_calls' => [self::toolCallArguments(0, '{"timezone":"UTC"}')]]),
            self::chunk(['tool_calls' => [self::toolCallStart(1, 'call_2', 'get_time')]]),
            self::chunk(['tool_calls' => [self::toolCallArguments(1, '{"timezone":"JST"}')]]),
            self::chunk([], 'tool_calls'),
        ]);

        $events = self::pairs(self::stream($http, '', [Message::user('time?')], [self::getTimeTool()]));

        static::assertSame(
            [
                [StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'get_time']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => '{"timezone":"UTC"}']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::TOOL_USE_START, ['id' => 'call_2', 'name' => 'get_time']],
                [StreamEvent::TOOL_USE_DELTA, ['input' => '{"timezone":"JST"}']],
                [StreamEvent::CONTENT_BLOCK_STOP, []],
                [StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']],
            ],
            $events,
        );
    }

    public function testStreamWithoutFinishReasonThrowsAfterYieldedDeltas(): void
    {
        $http = self::scriptedHttp([
            self::chunk(['role' => 'assistant', 'content' => '']),
            self::chunk(['content' => 'Hello']),
            self::chunk(['content' => ' world']),
        ]);
        $client = new OpenAiStreamingLlmClient(
            OpenAiClientBuilder::build($http),
            new OpenAiMessageMapper(),
            new OpenAiToolMapper(),
            'stub-model',
        );

        $events = [];
        $caught = null;
        try {
            foreach ($client->chatStream('', [Message::user('hi')], []) as $event) {
                $events[] = $event;
            }
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        static::assertNotNull($caught);
        static::assertSame('LLM stream ended without finish_reason', $caught->getMessage());
        static::assertSame(
            [
                [StreamEvent::TEXT_DELTA, ['text' => 'Hello']],
                [StreamEvent::TEXT_DELTA, ['text' => ' world']],
            ],
            self::pairs($events),
        );
    }

    public function testFinishReasonVariantsMapToStopReason(): void
    {
        $mapping = [
            'stop' => 'end_turn',
            'length' => 'end_turn',
            'content_filter' => 'end_turn',
            'tool_calls' => 'tool_use',
            'function_call' => 'tool_use',
        ];

        foreach ($mapping as $finishReason => $stopReason) {
            $http = self::scriptedHttp([
                self::chunk(['content' => 'x']),
                self::chunk([], $finishReason),
            ]);

            $events = self::stream($http, '', [Message::user('hi')], []);
            $last = $events[array_key_last($events)];

            static::assertSame(StreamEvent::MESSAGE_STOP, $last->type, "finish_reason={$finishReason}");
            static::assertSame(['stopReason' => $stopReason], $last->data, "finish_reason={$finishReason}");
        }
    }

    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return list<StreamEvent>
     */
    private static function stream(StubHttpClient $http, string $system, array $messages, array $tools): array
    {
        $client = new OpenAiStreamingLlmClient(
            OpenAiClientBuilder::build($http),
            new OpenAiMessageMapper(),
            new OpenAiToolMapper(),
            'stub-model',
        );

        return iterator_to_array($client->chatStream($system, $messages, $tools), false);
    }

    /**
     * @param list<StreamEvent> $events
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private static function pairs(array $events): array
    {
        return array_map(static fn(StreamEvent $event): array => [$event->type, $event->data], $events);
    }

    private static function cannedHttp(): StubHttpClient
    {
        return new StubHttpClient(
            static fn(array $requestBody): iterable => (new CannedConversation(self::CREATED))->respond($requestBody),
        );
    }

    /** @param list<array<string, mixed>> $chunks */
    private static function scriptedHttp(array $chunks): StubHttpClient
    {
        return new StubHttpClient(static fn(array $requestBody): iterable => $chunks);
    }

    /**
     * @param array<string, mixed> $delta
     *
     * @return array<string, mixed> chat.completion.chunk payload
     */
    private static function chunk(array $delta, string|null $finishReason = null): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'created' => self::CREATED,
            'model' => 'stub-model',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => $delta === [] ? new stdClass() : $delta,
                    'finish_reason' => $finishReason,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> first tool_calls fragment (carries id/name, empty arguments) */
    private static function toolCallStart(int $index, string $id, string $name): array
    {
        return [
            'index' => $index,
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => ''],
        ];
    }

    /** @return array<string, mixed> follow-up tool_calls fragment (arguments only) */
    private static function toolCallArguments(int $index, string $arguments): array
    {
        return ['index' => $index, 'function' => ['arguments' => $arguments]];
    }

    private static function getTimeTool(): Tool
    {
        return new Tool('get_time', 'Returns the current time.', [
            'type' => 'object',
            'properties' => ['timezone' => ['type' => 'string']],
            'required' => [],
        ]);
    }
}
