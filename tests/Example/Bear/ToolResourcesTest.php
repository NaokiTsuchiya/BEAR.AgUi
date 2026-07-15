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
use GuzzleHttp\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function array_map;
use function is_dir;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

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
        static::assertSame('Tokyo', $ro->body['city'] ?? null);
        static::assertSame('sunny', $ro->body['condition'] ?? null);
    }

    public function testNewsReturnsHeadlineForTopic(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/news', ['topic' => 'php']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('php', $ro->body['topic'] ?? null);
        static::assertNotSame('', $ro->body['headline'] ?? null);
    }

    public function testMessagePostReportsSent(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->post('app://self/message', ['to' => 'alice@example.com', 'body' => 'hi']);

        static::assertSame(201, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue($ro->body['sent'] ?? false);
        static::assertSame('alice@example.com', $ro->body['to'] ?? null);
    }

    public function testReminderPutUpserts(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->put('app://self/reminder', ['id' => 'r-1', 'text' => 'buy milk']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue($ro->body['saved'] ?? false);
        static::assertSame('r-1', $ro->body['id'] ?? null);
    }

    public function testPackageSearchReturnsTopResultForQuery(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/package', ['query' => 'bear/tool-use']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue($ro->body['found'] ?? null);
        static::assertSame('bear/tool-use', $ro->body['name']);
        static::assertSame(12_345, $ro->body['downloads']);
    }

    public function testWordSimilarityComparesTwoPhrases(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/similarity', ['a' => 'PHP', 'b' => 'Perl']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('PHP', $ro->body['a'] ?? null);
        static::assertSame('Perl', $ro->body['b'] ?? null);
        static::assertIsFloat($ro->body['similarity_percent'] ?? null);
        static::assertIsInt($ro->body['levenshtein_distance'] ?? null);
    }

    public function testRot13EncodesAndRoundTrips(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/rot13', ['text' => 'BEAR.Sunday']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertNotSame('BEAR.Sunday', $ro->body['output'] ?? null);

        $roundTrip = $resource->get('app://self/rot13', ['text' => $ro->body['output'] ?? null]);
        static::assertIsArray($roundTrip->body);
        static::assertSame('BEAR.Sunday', $roundTrip->body['output'] ?? null);
    }

    public function testCollectorDerivesToolDeclarationsAndFillsRegistry(): void
    {
        $injector = self::injector();
        $collector = $injector->getInstance(ToolCollectorInterface::class);

        $tools = $collector->collect(ToolUris::ALL);

        static::assertSame(
            [
                'weather_get',
                'news_get',
                'message_post',
                'reminder_put',
                'package_search',
                'word_similarity_get',
                'rot13_get',
            ],
            array_map(static fn(Tool $tool): string => $tool->name, $tools),
        );
        static::assertSame(
            [false, false, false, true, false, false, false],
            array_map(static fn(Tool $tool): bool => $tool->confirm, $tools),
        );

        // Collection side effect: the resource-driven Dispatcher's registry
        // now maps each tool name to its resource URI + method.
        $registry = $injector->getInstance(ToolRegistryInterface::class);
        static::assertSame(
            [
                'weather_get',
                'news_get',
                'message_post',
                'reminder_put',
                'package_search',
                'word_similarity_get',
                'rot13_get',
            ],
            $registry->getToolNames(),
        );
        $mapping = $registry->get('reminder_put');
        static::assertNotNull($mapping);
        static::assertSame('app://self/reminder', $mapping->resourceUri);
        static::assertSame('put', $mapping->method);
    }

    private static function injector(): InjectorInterface
    {
        $tmp = sys_get_temp_dir() . '/bear-agui-example-di-resources';
        $tmpExists = is_dir($tmp);
        if (!$tmpExists) {
            mkdir($tmp, 0o777, true);
        }

        return new Injector(
            new class($tmp) extends AbstractModule {
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
                    $this->bind(HttpClientInterface::class)->toInstance(self::fakePackagistClient());
                }

                // Package.php's httpClient dependency, resolved to a canned
                // Packagist response — no test ever touches the real network.
                private function fakePackagistClient(): HttpClientInterface
                {
                    return new class implements HttpClientInterface {
                        #[Override]
                        public function sendRequest(RequestInterface $request): ResponseInterface
                        {
                            $payload = [
                                'results' => [[
                                    'name' => 'bear/tool-use',
                                    'description' => 'Tool use for BEAR.Sunday',
                                    'url' => 'https://packagist.org/packages/bear/tool-use',
                                    'downloads' => 12_345,
                                ]],
                            ];

                            return new Response(
                                200,
                                ['Content-Type' => 'application/json'],
                                json_encode($payload, JSON_THROW_ON_ERROR),
                            );
                        }
                    };
                }
            },
            $tmp,
        );
    }
}
