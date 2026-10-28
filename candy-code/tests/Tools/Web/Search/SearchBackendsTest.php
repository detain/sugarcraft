<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Web\Search;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Tools\ToolLimits;
use SugarCraft\Code\Tools\Web\Search\BraveBackend;
use SugarCraft\Code\Tools\Web\Search\ExaBackend;
use SugarCraft\Code\Tools\Web\Search\SearchBackend;
use SugarCraft\Code\Tools\Web\Search\SearchBackends;
use SugarCraft\Code\Tools\Web\Search\SearxngBackend;
use SugarCraft\Code\Tools\Web\Search\TavilyBackend;

/**
 * The backend choice for C-TOOL-2.b: what `webSearchBackend` resolves to,
 * and — the law that matters — that picking `searxng` by default is
 * byte-identity with the tool as it shipped before the split. The settings
 * path never reaches the factory's unknown-name refusal because the schema
 * validates the enum first; the refusal exists so a hand-built caller cannot
 * be silently served the self-hosted backend, and both halves are pinned.
 */
final class SearchBackendsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private const ENV_KEYS = ['CANDY_CODE_SEARCH_ENDPOINT', 'BRAVE_SEARCH_API_KEY', 'TAVILY_API_KEY', 'EXA_API_KEY'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_backend_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $saved = $this->savedEnv[$key];
            putenv($saved === false ? $key : $key . '=' . $saved);
        }
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testDefaultAndExplicitSearxngBothBuildTheSelfHostedBackend(): void
    {
        $default = SearchBackends::make(null, 'https://searx.example/search', 30);
        $named = SearchBackends::make('searxng', 'https://searx.example/search', 30);
        $empty = SearchBackends::make('', 'https://searx.example/search', 30);

        self::assertInstanceOf(SearxngBackend::class, $default);
        self::assertInstanceOf(SearxngBackend::class, $named);
        self::assertInstanceOf(SearxngBackend::class, $empty);
        self::assertSame('searxng', $default->name());
        self::assertSame('searxng', $named->name());
    }

    /**
     * Byte-identity is the claim of the whole refactor for the default path:
     * the unconfigured refusal is reproduced exactly, so any prompt, test or
     * user that matched the old text still matches it.
     */
    public function testTheSearxngConfigurationRefusalIsByteIdenticalToThePreSplitText(): void
    {
        $expected = 'Error: no search endpoint is configured, so WebSearch cannot run. Set CANDY_CODE_SEARCH_ENDPOINT '
            . '(or the webSearchEndpoint setting) to the search URL of a SearXNG instance you trust '
            . '(for example https://searx.example.org/search) and restart; there is no built-in default.';

        $backend = SearchBackends::make(null, null, 30);

        self::assertFalse($backend->isConfigured());
        self::assertSame($expected, $backend->configurationRefusal());

        $seen = [];
        $result = (new WebSearch(transport: $this->recordingTransport($seen)))->execute(['query' => 'php', 'description' => 't']);
        self::assertTrue($result->isError());
        self::assertSame($expected, $result->content());
        self::assertSame([], $seen, 'the refusal happens before any dial');
    }

    public function testTheToolRoutesTheSearxngPathWithThePreSplitRequestShape(): void
    {
        $seen = [];
        $tool = new WebSearch(
            'https://searx.example.org/search',
            transport: $this->recordingTransport($seen, '{"results":[]}'),
            resolveAddresses: static fn (string $host): array => ['93.184.215.14'],
        );

        $result = $tool->execute(['query' => 'php', 'description' => 't']);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('No results found for the query.', $result->content());
        self::assertSame('https://searx.example.org/search?q=php&format=json', $seen['url']);
        self::assertSame('GET', $seen['method']);
        self::assertSame([], $seen['headers']);
        self::assertNull($seen['body']);
    }

    public function testAnUnknownNameFromAHandBuiltCallerIsRefusedByName(): void
    {
        try {
            SearchBackends::make('bogus', 'https://searx.example/search', 30);
            self::fail('the factory accepted an unknown backend name');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('bogus', $e->getMessage());
            foreach (SearchBackends::NAMES as $name) {
                self::assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    public function testTheSettingIsAUserTierEnumDefaultingToSearxng(): void
    {
        $definition = SettingsSchema::byKey(ToolLimits::WEB_SEARCH_BACKEND_KEY);

        self::assertNotNull($definition);
        self::assertSame('searxng', $definition->default);
        self::assertSame(SearchBackends::NAMES, $definition->enumValues);
        self::assertSame(RiskClass::Egress, $definition->riskClass);
        self::assertTrue($definition->layered);
        self::assertFalse($definition->projectSettable, 'a checked-out repository cannot choose the search host');
        self::assertContains(ToolLimits::WEB_SEARCH_BACKEND_KEY, LayeredSettings::userTierOnlyKeys());
    }

    public function testTheHostedChoicesBuildTheirOwnClasses(): void
    {
        self::assertInstanceOf(BraveBackend::class, SearchBackends::make('brave', null, 30));
        self::assertInstanceOf(TavilyBackend::class, SearchBackends::make('tavily', null, 30));
        self::assertInstanceOf(ExaBackend::class, SearchBackends::make('exa', null, 30));
    }

    /**
     * The mid-session law, copied from the endpoint key: the choice is read at
     * construction, so a tool built after a settings change dials the NEW
     * host — and a stale spelling is never cached away.
     */
    public function testASettingsChangeMidSessionIsHonoredAtTheNextConstruction(): void
    {
        $seen = [];
        $recorder = $this->recordingTransport($seen, '{"results":[]}');

        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_ENDPOINT_KEY => 'https://one.example/search',
        ]);
        $first = new WebSearch(transport: $recorder, resolveAddresses: $this->publicResolver());
        self::assertInstanceOf(SearxngBackend::class, $this->backend($first));
        $first->execute(['query' => 'php', 'description' => 't']);
        self::assertStringStartsWith('https://one.example/search?', $seen['url']);

        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_ENDPOINT_KEY => 'https://one.example/search',
            ToolLimits::WEB_SEARCH_BACKEND_KEY => 'brave',
        ]);
        $second = new WebSearch(transport: $recorder, resolveAddresses: $this->publicResolver());
        self::assertInstanceOf(BraveBackend::class, $this->backend($second));
        // No BRAVE_SEARCH_API_KEY is set in this sandbox, so the brave-backed
        // tool refuses by name instead of dialling — the proof it re-read.
        $result = $second->execute(['query' => 'php', 'description' => 't']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('BRAVE_SEARCH_API_KEY', $result->content());
        self::assertStringStartsWith('https://one.example/search?', $seen['url'], 'nothing new was dialled');

        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_ENDPOINT_KEY => 'https://two.example/search',
            ToolLimits::WEB_SEARCH_BACKEND_KEY => 'searxng',
        ]);
        $third = new WebSearch(transport: $recorder, resolveAddresses: $this->publicResolver());
        self::assertInstanceOf(SearxngBackend::class, $this->backend($third), 'switching back to searxng mid-session is honored too');
        $third->execute(['query' => 'php', 'description' => 't']);
        self::assertStringStartsWith('https://two.example/search?', $seen['url']);
    }

    /**
     * The settings layer cannot smuggle an unknown enum through to the
     * factory: a bad spelling is dropped by schema validation and the tool
     * keeps its default searxng behaviour, byte-identical.
     */
    public function testABadEnumSpellingInSettingsFallsBackToTheDefaultBackend(): void
    {
        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_ENDPOINT_KEY => 'https://searx.example.org/search',
            ToolLimits::WEB_SEARCH_BACKEND_KEY => 'Brave',
        ]);

        $seen = [];
        $tool = new WebSearch(
            transport: $this->recordingTransport($seen, '{"results":[]}'),
            resolveAddresses: $this->publicResolver(),
        );

        self::assertInstanceOf(SearxngBackend::class, $this->backend($tool));
        $result = $tool->execute(['query' => 'php', 'description' => 't']);
        self::assertFalse($result->isError(), $result->content());
        self::assertSame('https://searx.example.org/search?q=php&format=json', $seen['url']);
    }

    public function testATrustedProjectFileCannotChooseTheBackend(): void
    {
        $root = $this->dir . '/project';
        mkdir($root . '/.candy-code', 0700, true);
        file_put_contents($root . '/.candy-code/settings.json', json_encode([
            ToolLimits::WEB_SEARCH_BACKEND_KEY => 'brave',
            ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => 4,
        ]));

        $layer = LayeredSettings::projectLayer($root, true);

        self::assertArrayNotHasKey(ToolLimits::WEB_SEARCH_BACKEND_KEY, $layer);
        self::assertSame(4, $layer[ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY] ?? null, 'the results cap beside it is project-settable');
    }

    /**
     * @param array<string, mixed>|null $seen
     */
    private function recordingTransport(?array &$seen, string $body = '{}'): callable
    {
        return static function (array $request, string $dial) use (&$seen, $body): array {
            $seen = $request;

            return [$body, ['HTTP/1.1 200 OK']];
        };
    }

    private function publicResolver(): callable
    {
        return static fn (string $host): array => ['93.184.215.14'];
    }

    private function backend(WebSearch $tool): SearchBackend
    {
        return (new \ReflectionProperty(WebSearch::class, 'backend'))->getValue($tool);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
