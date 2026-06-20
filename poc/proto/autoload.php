<?php

declare(strict_types=1);

/*
 * Minimal PSR-4 autoloader for the prototype (no composer needed).
 *   BEAR\AgUi\  -> src/
 * Plus the ToolUse stub (proto/ToolUseStub.php) loaded explicitly.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'BEAR\\AgUi\\';
    $baseDir = __DIR__ . '/src/';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    // Event/* classes all live in Event/Events.php (multiple classes per file).
    if (str_starts_with($relative, 'Event\\') && $relative !== 'Event\\AgUiEventInterface') {
        $eventsFile = $baseDir . 'Event/Events.php';
        if (is_file($eventsFile)) {
            require $eventsFile;

            return;
        }
    }

    $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;

        return;
    }
});

require __DIR__ . '/proto/ToolUseStub.php';
