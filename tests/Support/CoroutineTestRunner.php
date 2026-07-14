<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use Throwable;

use function Swoole\Coroutine\run;

/**
 * Runs a test body inside a Swoole coroutine scheduler so code that needs a
 * coroutine context (WaitGroup, Coroutine::sleep) can execute under PHPUnit.
 *
 * Assertion failures and other throwables are captured inside the scheduler
 * and re-thrown after it drains, so PHPUnit reports them normally.
 */
final class CoroutineTestRunner
{
    /**
     * @param callable(): void $test
     *
     * @throws Throwable
     */
    public static function run(callable $test): void
    {
        $error = null;
        run(static function () use ($test, &$error): void {
            try {
                $test();
            } catch (Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }
}
