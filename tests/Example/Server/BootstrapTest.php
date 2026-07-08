<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Server;

use Example\Server\Bootstrap;
use NaokiTsuchiya\BEARAgUi\AgUiRunner;
use NaokiTsuchiya\BEARAgUi\Sse\SseResponder;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;

/**
 * Totality contract of the example bootstrap (T5/D18): building the app
 * must never depend on the environment being configured. With every
 * OPENAI_* variable unset, buildRunner() still returns a fully wired
 * runner — OpenAI::factory()->make() only assembles the client and performs
 * no I/O, so missing or wrong configuration surfaces as RUN_ERROR inside a
 * run (D11), never as a bootstrap crash before the stream opens.
 */
#[CoversClass(Bootstrap::class)]
final class BootstrapTest extends TestCase
{
    private const ENV_KEYS = ['OPENAI_API_KEY', 'OPENAI_BASE_URL', 'OPENAI_MODEL'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    #[Override]
    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testBuildRunnerReturnsWiredRunnerWhenEnvIsUnset(): void
    {
        $runner = Bootstrap::buildRunner();

        static::assertInstanceOf(AgUiRunner::class, $runner);
    }

    public function testBuildRunnerReturnsWiredRunnerWhenEnvIsSet(): void
    {
        putenv('OPENAI_API_KEY=sk-test');
        putenv('OPENAI_BASE_URL=http://127.0.0.1:8081/v1/');
        putenv('OPENAI_MODEL=gpt-4o');

        $runner = Bootstrap::buildRunner();

        static::assertInstanceOf(AgUiRunner::class, $runner);
    }

    public function testBuildResponderReturnsSseResponder(): void
    {
        $responder = Bootstrap::buildResponder();

        static::assertInstanceOf(SseResponder::class, $responder);
    }
}
