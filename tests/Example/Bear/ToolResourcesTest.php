<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Example\Bear;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Module\ToolUseModule;
use BEAR\ToolUse\Schema\Tool;
use BEAR\ToolUse\Schema\ToolCollectorInterface;
use Example\Bear\ToolUris;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function array_map;
use function is_dir;
use function mkdir;
use function sys_get_temp_dir;

/**
 * The #[Tool] resources through a real Injector (tasks-m3 T3): resource
 * behavior via ResourceInterface, and the tool declarations / registry
 * mappings ToolCollector derives from the attributes. Deliberately wired
 * from BEAR.Resource + ToolUseModule only — the app's own modules get
 * their coverage from the governance / integration tests.
 */
#[CoversNothing]
final class ToolResourcesTest extends TestCase
{
    public function testWeatherReturnsCannedConditionsForCity(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/weather', ['city' => 'Tokyo']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('Tokyo', $ro->body['city']);
        static::assertSame('sunny', $ro->body['condition']);
    }

    public function testNewsReturnsHeadlineForTopic(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/news', ['topic' => 'php']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('php', $ro->body['topic']);
        static::assertNotSame('', $ro->body['headline']);
    }

    public function testMessagePostReportsSent(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->post('app://self/message', ['to' => 'alice@example.com', 'body' => 'hi']);

        static::assertSame(201, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue($ro->body['sent']);
        static::assertSame('alice@example.com', $ro->body['to']);
    }

    public function testReminderPutUpserts(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->put('app://self/reminder', ['id' => 'r-1', 'text' => 'buy milk']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue($ro->body['saved']);
        static::assertSame('r-1', $ro->body['id']);
    }

    public function testCollectorDerivesToolDeclarationsAndFillsRegistry(): void
    {
        $injector = self::injector();
        $collector = $injector->getInstance(ToolCollectorInterface::class);

        $tools = $collector->collect(ToolUris::ALL);

        static::assertSame(
            ['weather_get', 'news_get', 'message_post', 'reminder_put'],
            array_map(static fn(Tool $tool): string => $tool->name, $tools),
        );
        static::assertSame(
            [false, false, false, true],
            array_map(static fn(Tool $tool): bool => $tool->confirm, $tools),
        );

        // Collection side effect: the resource-driven Dispatcher's registry
        // now maps each tool name to its resource URI + method.
        $registry = $injector->getInstance(ToolRegistryInterface::class);
        static::assertSame(['weather_get', 'news_get', 'message_post', 'reminder_put'], $registry->getToolNames());
        $mapping = $registry->get('reminder_put');
        static::assertNotNull($mapping);
        static::assertSame('app://self/reminder', $mapping->resourceUri);
        static::assertSame('put', $mapping->method);
    }

    private static function injector(): InjectorInterface
    {
        $tmp = sys_get_temp_dir() . '/bear-agui-example-di-resources';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0o777, true);
        }

        return new Injector(new class($tmp) extends AbstractModule {
            public function __construct(
                private readonly string $schemaDir,
            ) {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->install(new ResourceModule('Example\\Bear'));
                $this->install(new ToolUseModule());
                $this->bind()->annotatedWith('json_validate_dir')->toInstance($this->schemaDir);
            }
        }, $tmp);
    }
}
