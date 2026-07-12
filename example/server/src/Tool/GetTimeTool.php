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
        $requested = $input['timezone'] ?? null;
        $timezone = is_string($requested) ? $this->zoneOrUtc($requested) : self::utc();

        return (new DateTimeImmutable('now', $timezone))->format(DateTimeInterface::ATOM);
    }

    private function zoneOrUtc(string $identifier): DateTimeZone
    {
        try {
            return new DateTimeZone($identifier);
        } catch (Exception) {
            return self::utc();
        }
    }

    /**
     * 'UTC' is a valid identifier by definition — the ctor's documented
     * throw cannot occur here.
     *
     * @mago-expect analysis:unhandled-thrown-type
     */
    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
