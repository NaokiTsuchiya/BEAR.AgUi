<?php

declare(strict_types=1);

namespace Example\Server\Tool;

use BEAR\ToolUse\Schema\Tool;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

use function is_string;

/**
 * Demo tool: returns the real current time (D21 — determinism is not
 * required; the integration tests drive the pipeline through fakes and
 * never reach this code path, while the stub LLM echoes this result so
 * the demo stays coherent).
 *
 * Holds both the Schema\Tool definition advertised to the LLM and the
 * execution logic {@see DemoDispatcher} delegates to.
 */
final readonly class GetTimeTool
{
    public const NAME = 'get_time';

    public function definition(): Tool
    {
        return new Tool(
            self::NAME,
            'Returns the current time. Accepts an optional IANA timezone identifier (defaults to UTC).',
            [
                'type' => 'object',
                'properties' => [
                    'timezone' => ['type' => 'string'],
                ],
                'required' => [],
            ],
        );
    }

    /**
     * Formats the current time, honoring a valid `timezone` input and
     * silently falling back to UTC otherwise (a demo tool should answer,
     * not lecture the model about IANA identifiers).
     *
     * @param array<string, mixed> $input
     */
    public function __invoke(array $input): string
    {
        $timezone = new DateTimeZone('UTC');
        if (isset($input['timezone']) && is_string($input['timezone'])) {
            try {
                $timezone = new DateTimeZone($input['timezone']);
            } catch (Exception) {
                // Invalid identifier: keep the UTC default.
            }
        }

        return (new DateTimeImmutable('now', $timezone))->format(DateTimeInterface::ATOM);
    }
}
