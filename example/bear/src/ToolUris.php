<?php

declare(strict_types=1);

namespace Example\Bear;

/**
 * The single definition of which resources are exposed as tools (tasks-m3
 * T4): the AgentFactoryProvider collects these once at boot; the ALPS
 * profile (alps/profile.xml) governs the derived tool names.
 */
final class ToolUris
{
    /** @var list<string> */
    public const ALL = [
        'app://self/weather',
        'app://self/news',
        'app://self/message',
        'app://self/reminder',
        'app://self/package',
        'app://self/similarity',
        'app://self/rot13',
        'app://self/sun-info',
    ];

    /** Static holder only. */
    private function __construct() {}
}
