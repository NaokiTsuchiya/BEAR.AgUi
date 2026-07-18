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
use RuntimeException;

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
 *
 * One method per #[Tool] resource plus the collector/registry wiring test,
 * same convention as InvocationsTest.
 */
#[CoversNothing]
final class ToolResourcesTest extends TestCase
{
    public function testMessagePostReportsSent(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->post('app://self/message', ['to' => 'alice@example.com', 'body' => 'hi']);

        static::assertSame(201, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue(self::field($ro->body, 'sent', false));
        static::assertSame('alice@example.com', self::field($ro->body, 'to'));
    }

    public function testReminderPutUpserts(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->put('app://self/reminder', ['id' => 'r-1', 'text' => 'buy milk']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue(self::field($ro->body, 'saved', false));
        static::assertSame('r-1', self::field($ro->body, 'id'));
    }

    public function testPackageSearchReturnsMatchingResultsForQuery(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/package', ['query' => 'bear/tool-use']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertTrue(self::field($ro->body, 'found'));
        static::assertSame(1, self::field($ro->body, 'count'));

        $results = self::field($ro->body, 'results');
        static::assertIsArray($results);
        $top = $results[0];
        static::assertIsArray($top);
        static::assertSame('bear/tool-use', self::field($top, 'name'));
        static::assertSame(12_345, self::field($top, 'downloads'));
    }

    public function testWordSimilarityComparesTwoPhrases(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/similarity', ['a' => 'PHP', 'b' => 'Perl']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('PHP', self::field($ro->body, 'a'));
        static::assertSame('Perl', self::field($ro->body, 'b'));
        static::assertIsFloat(self::field($ro->body, 'similarity_percent'));
        static::assertIsInt(self::field($ro->body, 'levenshtein_distance'));
    }

    public function testRot13EncodesAndRoundTrips(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/rot13', ['text' => 'BEAR.Sunday']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertNotSame('BEAR.Sunday', self::field($ro->body, 'output'));

        $roundTrip = $resource->get('app://self/rot13', ['text' => self::field($ro->body, 'output')]);
        static::assertIsArray($roundTrip->body);
        static::assertSame('BEAR.Sunday', self::field($roundTrip->body, 'output'));
    }

    public function testSunInfoReturnsSunriseAndSunsetForKnownCity(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $ro = $resource->get('app://self/sun-info', ['city' => 'Tokyo', 'date' => '2026-07-20']);

        static::assertSame(200, $ro->code);
        static::assertIsArray($ro->body);
        static::assertSame('Tokyo', self::field($ro->body, 'city'));
        static::assertSame('2026-07-20', self::field($ro->body, 'date'));
        static::assertMatchesRegularExpression('/^\d{2}:\d{2} UTC$/', (string) self::field($ro->body, 'sunrise_utc'));
        static::assertMatchesRegularExpression('/^\d{2}:\d{2} UTC$/', (string) self::field($ro->body, 'sunset_utc'));
    }

    public function testSunInfoRejectsUnknownCity(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $this->expectException(RuntimeException::class);
        $resource->get('app://self/sun-info', ['city' => 'Atlantis']);
    }

    public function testCollectorDerivesToolDeclarationsAndFillsRegistry(): void
    {
        $injector = self::injector();
        $collector = $injector->getInstance(ToolCollectorInterface::class);

        $tools = $collector->collect(ToolUris::ALL);

        static::assertSame(
            [
                'message_post',
                'package_search',
                'word_similarity_get',
                'rot13_get',
                'sun_info_get',
            ],
            array_map(static fn(Tool $tool): string => $tool->name, $tools),
        );
        static::assertSame(
            [false, false, false, false, false],
            array_map(static fn(Tool $tool): bool => $tool->confirm, $tools),
        );

        // Collection side effect: the resource-driven Dispatcher's registry
        // now maps each tool name to its resource URI + method.
        $registry = $injector->getInstance(ToolRegistryInterface::class);
        static::assertSame(
            [
                'message_post',
                'package_search',
                'word_similarity_get',
                'rot13_get',
                'sun_info_get',
            ],
            $registry->getToolNames(),
        );
        $mapping = $registry->get('rot13_get');
        static::assertNotNull($mapping);
        static::assertSame('app://self/rot13', $mapping->resourceUri);
        static::assertSame('get', $mapping->method);
    }

    /** @param array<array-key, mixed> $body */
    private static function field(array $body, int|string $key, mixed $default = null): mixed
    {
        return $body[$key] ?? $default;
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
