<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;
use DateTimeImmutable;
use RuntimeException;

use function date_sun_info;
use function is_bool;
use function strtolower;

/**
 * PHP's date_sun_info() computes sunrise/sunset/solar-noon times from pure
 * astronomical math — no API key, no network call, just a built-in function
 * most PHP developers have never reached for. Another "PHP already had
 * this" tool alongside rot13_get/word_similarity_get.
 *
 * City names resolve through a fixed lookup table rather than taking
 * latitude/longitude straight from the LLM: coordinates typed out by a
 * model are a guess, not a fact, and a live demo shouldn't depend on the
 * LLM recalling Tokyo's longitude correctly. Same shape as Weather::onGet().
 *
 * ALPS marks `sun_info_get` as `safe`, so it's confirm-free and joins the
 * parallel wave alongside the other read-only tools.
 */
final class SunInfo extends ResourceObject
{
    /** @var array<string, array{0: float, 1: float}> */
    private const CITY_COORDINATES = [
        'tokyo' => [35.6762, 139.6503],
        'osaka' => [34.6937, 135.5023],
        'fukuoka' => [33.5904, 130.4017],
        'sapporo' => [43.0618, 141.3545],
        'naha' => [26.2124, 127.6809],
    ];

    /**
     * @param string $city City name to look up (e.g. "Tokyo")
     * @param string $date Date to compute for, e.g. "2026-07-20"; defaults to today
     *
     * @throws RuntimeException If $city is not in the known city list.
     */
    #[Tool(
        name: 'sun_info_get',
        description: "Compute sunrise, sunset and solar noon for a known city and date using PHP's built-in date_sun_info()",
        confirm: false,
    )]
    public function onGet(string $city, string $date = 'today'): static
    {
        $coordinates = self::CITY_COORDINATES[strtolower($city)] ?? null;
        if ($coordinates === null) {
            throw new RuntimeException('Unknown city: ' . $city);
        }

        [$latitude, $longitude] = $coordinates;
        $timestamp = (new DateTimeImmutable($date))->getTimestamp();
        $info = date_sun_info($timestamp, $latitude, $longitude);

        $this->body = [
            'city' => $city,
            'date' => $date,
            'sunrise_utc' => $this->formatMoment($info['sunrise']),
            'sunset_utc' => $this->formatMoment($info['sunset']),
            'solar_noon_utc' => $this->formatMoment($info['transit']),
            'php_function' => 'date_sun_info()',
        ];

        return $this;
    }

    private function formatMoment(bool|int $moment): string
    {
        if (is_bool($moment)) {
            return $moment ? 'sun never sets that day' : 'sun never rises that day';
        }

        return (new DateTimeImmutable('@' . $moment))->format('H:i') . ' UTC';
    }
}
