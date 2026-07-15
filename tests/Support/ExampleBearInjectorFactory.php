<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Support;

use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use Example\Bear\Module\AppModule;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function is_dir;
use function mkdir;
use function sys_get_temp_dir;

/**
 * Builds the example/bear app injector for tests. Two flavors with separate
 * compile caches so the overridden binding never collides with the real one:
 * `app()` uses the real module as-is (the OpenAI client is lazy — no I/O at
 * build time), `withLlm()` swaps StreamingLlmClientInterface for a scripted
 * fake (D13/D22: tests never do HTTP).
 */
final class ExampleBearInjectorFactory
{
    public static function app(): InjectorInterface
    {
        return new Injector(new AppModule(), self::tmpDir('app'));
    }

    public static function withLlm(StreamingLlmClientInterface $llm): InjectorInterface
    {
        $module = new AppModule();
        $module->override(new class($llm) extends AbstractModule {
            public function __construct(
                private readonly StreamingLlmClientInterface $client,
            ) {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->bind(StreamingLlmClientInterface::class)->toInstance($this->client);
            }
        });

        return new Injector($module, self::tmpDir('fake-llm'));
    }

    private static function tmpDir(string $flavor): string
    {
        $dir = sys_get_temp_dir() . '/bear-agui-example-di-' . $flavor;
        $dirExists = is_dir($dir);
        if (!$dirExists) {
            mkdir($dir, 0o777, true);
        }

        return $dir;
    }
}
