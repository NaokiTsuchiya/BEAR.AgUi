<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Shared;

use BEAR\ToolUse\Schema\Tool;
use Example\Shared\OpenAiToolMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the bear Tool -> OpenAI function-tool mapping (T3-a, D20).
 * No CoversClass: example/ classes are outside the coverage include path.
 */
final class OpenAiToolMapperTest extends TestCase
{
    public function testMapsToolToOpenAiFunctionShape(): void
    {
        $tool = new Tool('get_time', 'Returns the current time.', [
            'type' => 'object',
            'properties' => ['timezone' => ['type' => 'string']],
            'required' => [],
        ]);

        $mapped = (new OpenAiToolMapper())->map([$tool]);

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
            $mapped,
        );
    }
}
