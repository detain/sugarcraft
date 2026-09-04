<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Tests for ProviderFactory - factory for creating providers from configuration.
 */
final class ProviderFactoryTest extends TestCase
{
    private ProviderFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new ProviderFactory();
    }

    // -------------------------------------------------------------------------
    // availableTypes()
    // -------------------------------------------------------------------------

    public function testAvailableTypesReturnsAllSevenTypes(): void
    {
        $types = $this->factory->availableTypes();

        $this->assertCount(7, $types);
        $this->assertSame([
            'openai',
            'anthropic',
            'claude-code',
            'sglang',
            'bedrock',
            'vertex',
            'custom',
        ], $types);
    }

    // -------------------------------------------------------------------------
    // defaultConfig()
    // -------------------------------------------------------------------------

    /**
     * @dataProvider providerTypesProvider
     */
    public function testDefaultConfigReturnsValidDefaultsForEachType(string $type): void
    {
        $config = $this->factory->defaultConfig($type);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('type', $config);
        $this->assertSame($type, $config['type']);
    }

    /**
     * @dataProvider providerTypesProvider
     */
    public function testDefaultConfigThrowsOnUnknownType(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown provider type: unknown-type");

        $this->factory->defaultConfig('unknown-type');
    }

    public static function providerTypesProvider(): array
    {
        return [
            'openai' => ['openai'],
            'anthropic' => ['anthropic'],
            'claude-code' => ['claude-code'],
            'sglang' => ['sglang'],
            'bedrock' => ['bedrock'],
            'vertex' => ['vertex'],
            'custom' => ['custom'],
        ];
    }

    public function testDefaultConfigOpenaiHasRequiredKeys(): void
    {
        $config = $this->factory->defaultConfig('openai');

        $this->assertArrayHasKey('type', $config);
        $this->assertArrayHasKey('apiKey', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertSame('openai', $config['type']);
        $this->assertSame('gpt-4o', $config['model']);
    }

    public function testDefaultConfigAnthropicHasRequiredKeys(): void
    {
        $config = $this->factory->defaultConfig('anthropic');

        $this->assertArrayHasKey('type', $config);
        $this->assertArrayHasKey('apiKey', $config);
        $this->assertArrayHasKey('baseUrl', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertSame('anthropic', $config['type']);
        $this->assertSame('https://api.anthropic.com', $config['baseUrl']);
        $this->assertSame('claude-sonnet-4-6', $config['model']);
    }

    public function testDefaultConfigClaudeCodeHasRequiredKeys(): void
    {
        $config = $this->factory->defaultConfig('claude-code');

        $this->assertArrayHasKey('type', $config);
        $this->assertArrayHasKey('claudePath', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertSame('claude-code', $config['type']);
        $this->assertSame('claude', $config['claudePath']);
        $this->assertSame('claude-sonnet-4-6', $config['model']);
    }

    public function testDefaultConfigSglangHasRequiredKeys(): void
    {
        $config = $this->factory->defaultConfig('sglang');

        $this->assertArrayHasKey('type', $config);
        $this->assertArrayHasKey('baseUrl', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertSame('sglang', $config['type']);
        $this->assertSame('http://localhost:30000', $config['baseUrl']);
        // The built-in `sglang` default model is the id the confirmed skynet2
        // deployment actually serves. Asserted against SglangProvider's own
        // constant rather than a repeated literal so the two cannot drift, and
        // paired with an explicit assertion that it is NOT the retired
        // MiniMax-M2.7 - that id is gone from the server, so a default naming
        // it 404s on every request.
        $this->assertSame(SglangProvider::DEFAULT_MODEL, $config['model']);
        $this->assertNotSame('MiniMax-M2.7', $config['model']);
        // Present-but-null: the effective effort is derived from the model, so
        // a literal here would outlive an edit to `model`.
        $this->assertArrayHasKey('reasoningEffort', $config);
        $this->assertNull($config['reasoningEffort']);
    }

    public function testDefaultConfigBedrockHasRequiredKeys(): void
    {
        $config = $this->factory->defaultConfig('bedrock');

        $this->assertArrayHasKey('type', $config);
        $this->assertArrayHasKey('region', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertSame('bedrock', $config['type']);
        $this->assertSame('us-east-1', $config['region']);
        $this->assertSame('anthropic.claude-sonnet-4-6', $config['model']);
    }

    public function testDefaultConfigVertexHasRequiredKeys(): void
    {
        $config = $this->factory->defaultConfig('vertex');

        $this->assertArrayHasKey('type', $config);
        $this->assertArrayHasKey('projectId', $config);
        $this->assertArrayHasKey('location', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertSame('vertex', $config['type']);
        $this->assertSame('us-central1', $config['location']);
        $this->assertSame('claude-3-sonnet@20240229', $config['model']);
    }

    public function testDefaultConfigCustomHasRequiredKeys(): void
    {
        $config = $this->factory->defaultConfig('custom');

        $this->assertArrayHasKey('type', $config);
        $this->assertArrayHasKey('name', $config);
        $this->assertArrayHasKey('baseUrl', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertArrayHasKey('supportsStreaming', $config);
        $this->assertArrayHasKey('supportsFunctionCalling', $config);
        $this->assertSame('custom', $config['type']);
        $this->assertSame('http://localhost:8080', $config['baseUrl']);
        $this->assertTrue($config['supportsStreaming']);
        $this->assertTrue($config['supportsFunctionCalling']);
    }

    // -------------------------------------------------------------------------
    // resolveEnv()
    // -------------------------------------------------------------------------

    public function testResolveEnvWithNoVariablesReturnsOriginalValue(): void
    {
        $result = $this->factory->resolveEnv('just a plain string');

        $this->assertSame('just a plain string', $result);
    }

    public function testResolveEnvWithNullReturnsNull(): void
    {
        $result = $this->factory->resolveEnv(null);

        $this->assertNull($result);
    }

    public function testResolveEnvWithSimpleVarResolvesFromEnv(): void
    {
        // Set up environment variable
        putenv('TEST_SIMPLE_VAR=hello-world');

        try {
            $result = $this->factory->resolveEnv('prefix-${TEST_SIMPLE_VAR}-suffix');

            $this->assertSame('prefix-hello-world-suffix', $result);
        } finally {
            putenv('TEST_SIMPLE_VAR');
        }
    }

    public function testResolveEnvWithDefaultSyntaxUsesDefaultWhenUnset(): void
    {
        // Ensure the variable is not set
        putenv('UNSET_VAR');

        $result = $this->factory->resolveEnv('value-${UNSET_VAR:-fallback}');

        $this->assertSame('value-fallback', $result);
    }

    public function testResolveEnvWithDefaultSyntaxUsesDefaultWhenEmpty(): void
    {
        // Set variable to empty string
        putenv('EMPTY_VAR=');

        try {
            $result = $this->factory->resolveEnv('value-${EMPTY_VAR:-fallback}');

            // Empty string is treated same as unset
            $this->assertSame('value-fallback', $result);
        } finally {
            putenv('EMPTY_VAR');
        }
    }

    public function testResolveEnvWithDefaultSyntaxResolvesEnvWhenSet(): void
    {
        putenv('SET_VAR=actual-value');

        try {
            $result = $this->factory->resolveEnv('value-${SET_VAR:-fallback}');

            $this->assertSame('value-actual-value', $result);
        } finally {
            putenv('SET_VAR');
        }
    }

    public function testResolveEnvWithEmptyDefaultUsesEmptyString(): void
    {
        putenv('ANOTHER_UNSET_VAR');

        $result = $this->factory->resolveEnv('value-${ANOTHER_UNSET_VAR:-}');

        $this->assertSame('value-', $result);
    }

    public function testResolveEnvWithMultipleVariables(): void
    {
        putenv('VAR1=value1');
        putenv('VAR2=value2');

        try {
            $result = $this->factory->resolveEnv('${VAR1} and ${VAR2}');

            $this->assertSame('value1 and value2', $result);
        } finally {
            putenv('VAR1');
            putenv('VAR2');
        }
    }

    // -------------------------------------------------------------------------
    // create() - Error cases
    // -------------------------------------------------------------------------

    public function testCreateInvalidTypeThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown provider type: invalid');

        $this->factory->create(['type' => 'invalid']);
    }

    public function testCreateMissingRequiredKeyThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Provider type 'openai' requires 'apiKey' to be set");

        // openai requires apiKey
        $this->factory->create(['type' => 'openai']);
    }

    public function testCreateMissingRequiredKeyForCustomThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Provider type 'custom' requires 'name' to be set");

        // custom requires name, baseUrl, model
        $this->factory->create(['type' => 'custom']);
    }

    public function testCreateWithEmptyStringTypeThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown provider type: ');

        $this->factory->create(['type' => '']);
    }

    public function testCreateWithMissingTypeKeyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Config must have a "type" key');

        $this->factory->create(['apiKey' => 'test']);
    }

    public function testCreateWithInvalidJsonStringThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->factory->create('{not valid json');
    }

    public function testCreateWithEmptyJsonStringThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON string cannot be empty');

        $this->factory->create('   ');
    }

    public function testCreateWithNonArrayJsonThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON must decode to an array');

        $this->factory->create('"just a string"');
    }

    // -------------------------------------------------------------------------
    // create() - Success cases (verifying correct provider types are created)
    // -------------------------------------------------------------------------

    public function testCreateOpenAiCreatesOpenAIProvider(): void
    {
        // A dummy key is enough: \OpenAI::client() builds the client offline and
        // makes no network call until a request is actually issued.
        $provider = $this->factory->create([
            'type' => 'openai',
            'apiKey' => 'test-api-key',
            'model' => 'gpt-4o',
        ]);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('openai', $provider->name());

        // The configured model reached the provider (drives contextWindow()).
        $this->assertSame(128_000, $provider->contextWindow());

        // And a real OpenAI client implementing the contract was injected.
        $this->assertInstanceOf(
            \OpenAI\Contracts\ClientContract::class,
            $this->openAiClientOf($provider),
        );
    }

    public function testCreateCustomCreatesCustomProvider(): void
    {
        $provider = $this->factory->create([
            'type' => 'custom',
            'name' => 'my-custom-provider',
            'baseUrl' => 'https://api.example.com',
            'model' => 'gpt-4o',
            'apiKey' => 'test-key',
        ]);

        $this->assertInstanceOf(CustomProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('my-custom-provider', $provider->name());
    }

    public function testCreateCustomFromJsonStringCreatesCustomProvider(): void
    {
        $json = json_encode([
            'type' => 'custom',
            'name' => 'json-provider',
            'baseUrl' => 'https://api.json-provider.com',
            'model' => 'gpt-4o',
        ]);

        $provider = $this->factory->create($json);

        $this->assertInstanceOf(CustomProvider::class, $provider);
        $this->assertSame('json-provider', $provider->name());
    }

    public function testCreateSglangCreatesSglangProvider(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'test-model',
        ]);

        $this->assertInstanceOf(SglangProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('sglang', $provider->name());
    }

    public function testCreateBedrockCreatesBedrockProvider(): void
    {
        $provider = $this->factory->create([
            'type' => 'bedrock',
            'region' => 'us-west-2',
            'model' => 'anthropic.claude-sonnet-4-6',
        ]);

        $this->assertInstanceOf(BedrockProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('bedrock', $provider->name());
    }

    public function testCreateVertexCreatesVertexProvider(): void
    {
        // VertexProvider::create() no longer constructs the Google SDK client at
        // build time (the network call lives behind a lazy predictor seam), so
        // this runs without the AIPlatform library or credentials.
        $provider = $this->factory->create([
            'type' => 'vertex',
            'projectId' => 'test-project',
            'location' => 'us-central1',
            'model' => 'claude-3-sonnet@20240229',
        ]);

        $this->assertInstanceOf(VertexProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('vertex', $provider->name());
    }

    public function testCreateClaudeCodeCreatesClaudeCodeProvider(): void
    {
        $provider = $this->factory->create([
            'type' => 'claude-code',
            'claudePath' => '/usr/local/bin/claude',
            'model' => 'claude-sonnet-4-6',
        ]);

        $this->assertInstanceOf(ClaudeCodeProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('claude-code', $provider->name());
    }

    public function testCreateAnthropicCreatesCustomProvider(): void
    {
        // Anthropic uses CustomProvider internally
        $provider = $this->factory->create([
            'type' => 'anthropic',
            'apiKey' => 'test-anthropic-key',
            'model' => 'claude-sonnet-4-6',
        ]);

        // Anthropic returns a CustomProvider (openAiCompatible)
        $this->assertInstanceOf(CustomProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('anthropic', $provider->name());
    }

    // -------------------------------------------------------------------------
    // create() - Environment variable resolution integration
    // -------------------------------------------------------------------------

    public function testCreateResolvesEnvVariablesInConfig(): void
    {
        putenv('FACTORY_TEST_API_KEY=env-resolved-key');

        try {
            $provider = $this->factory->create([
                'type' => 'openai',
                'apiKey' => '${FACTORY_TEST_API_KEY}',
                'model' => 'gpt-4o',
            ]);

            // Provider was created => the ${VAR} apiKey resolved to a non-empty
            // value (an empty apiKey would have failed required-key validation).
            $this->assertInstanceOf(OpenAIProvider::class, $provider);
        } finally {
            putenv('FACTORY_TEST_API_KEY');
        }
    }

    public function testCreateResolvesEnvVariablesWithDefaults(): void
    {
        // Ensure var is not set
        putenv('FACTORY_UNSET_VAR');

        try {
            $provider = $this->factory->create([
                'type' => 'openai',
                'apiKey' => '${FACTORY_UNSET_VAR:-default-api-key}',
                'model' => 'gpt-4o',
            ]);

            // Should have used the default value since the var is not set; the
            // non-empty default satisfies required-key validation.
            $this->assertInstanceOf(OpenAIProvider::class, $provider);
        } finally {
            putenv('FACTORY_UNSET_VAR');
        }
    }

    // -------------------------------------------------------------------------
    // create() - Optional parameters
    // -------------------------------------------------------------------------

    public function testCreateOpenAiWithOptionalOrganization(): void
    {
        $provider = $this->factory->create([
            'type' => 'openai',
            'apiKey' => 'test-key',
            'organization' => 'my-org',
            'model' => 'gpt-4o',
        ]);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertInstanceOf(\OpenAI\Contracts\ClientContract::class, $this->openAiClientOf($provider));
    }

    public function testCreateCustomWithOptionalStreamingSupport(): void
    {
        $provider = $this->factory->create([
            'type' => 'custom',
            'name' => 'no-stream-provider',
            'baseUrl' => 'https://api.example.com',
            'model' => 'gpt-4o',
            'supportsStreaming' => false,
            'supportsFunctionCalling' => true,
        ]);

        $this->assertInstanceOf(CustomProvider::class, $provider);
        $this->assertFalse($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
    }

    /**
     * Reads the private OpenAI client the factory injected, to prove the
     * configured client (not a fallback) was wired in.
     */
    private function openAiClientOf(OpenAIProvider $provider): object
    {
        $prop = (new \ReflectionClass(OpenAIProvider::class))->getProperty('client');
        $prop->setAccessible(true);

        /** @var object $client */
        $client = $prop->getValue($provider);

        return $client;
    }

    // -------------------------------------------------------------------------
    // fromProjectConfig() - reads .sugar-crush/config.dev.json off disk
    // -------------------------------------------------------------------------

    /**
     * Reproduces the R24.fix off-by-one finding: defaultConfigPath() must
     * resolve to THIS library's own .sugar-crush/config.dev.json (two `../`
     * up from src/Providers/), never to a directory one level above the
     * library root. The library root is computed independently here (from
     * this test file's own location, tests/Providers/ -> tests -> root)
     * rather than by delegating back to defaultConfigPath() itself, so the
     * assertion can't degenerate into comparing the method against itself.
     */
    public function testDefaultConfigPathPointsInsideThisLibraryNotItsParent(): void
    {
        $libraryRoot = dirname(__DIR__, 2);
        $expectedPath = $libraryRoot . '/.sugar-crush/config.dev.json';
        $wrongParentPath = dirname($libraryRoot) . '/.sugar-crush/config.dev.json';

        $resolved = ProviderFactory::defaultConfigPath();

        $this->assertStringEndsWith('/.sugar-crush/config.dev.json', $resolved);
        $this->assertFileExists($resolved);
        $this->assertSame(
            realpath($expectedPath),
            realpath($resolved),
            'defaultConfigPath() must resolve inside the sugar-crush library root, not one level above it'
        );
        $this->assertNotSame(
            realpath($wrongParentPath),
            realpath($resolved),
            'defaultConfigPath() must not overshoot into the directory above the library root'
        );
    }

    /**
     * THE TENTH FILE ON THE CONTAINMENT INVENTORY, and it held the same
     * `__DIR__`-relative construction as
     * {@see \SugarCraft\Crush\Agents\WorktreeConfig}'s — closed one round earlier —
     * with no containment of any kind: `__DIR__ . '/../../.sugar-crush/config.dev.json'`,
     * read by `fromProjectConfig()`, `projectProviderConfig()` and, at launch,
     * `Bootstrap::availableProviders()`. Its contents choose which provider a
     * session talks to, `baseUrl` and `apiKey` included.
     *
     * Two boundaries, driven separately against a synthetic package root because
     * they answer different questions and one cannot stand in for the other: a link
     * ON `.sugar-crush` relocates the per-file check, and a link on `config.dev.json`
     * is invisible to the directory check.
     *
     * @return array<string, array{0: string}>
     */
    public static function refusedProviderConfigLayouts(): array
    {
        return [
            'the config DIRECTORY is a link out of the package' => ['directory'],
            'the config FILE is a link out of its directory' => ['file'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedProviderConfigLayouts')]
    public function testAnEscapingProviderConfigIsRefused(string $layout): void
    {
        $tmp = sys_get_temp_dir() . '/pf_containment_' . uniqid('', true);
        $root = $tmp . '/package';
        $outside = $tmp . '/outside';
        mkdir($root, 0o700, true);
        mkdir($outside, 0o700, true);
        file_put_contents($outside . '/config.dev.json', json_encode([
            'defaultProvider' => 'evil',
            'providers' => ['evil' => ['type' => 'openai', 'apiKey' => 'sk-live-STOLEN']],
        ]));

        try {
            if ($layout === 'directory') {
                symlink($outside, $root . '/.sugar-crush');
            } else {
                mkdir($root . '/.sugar-crush', 0o700, true);
                symlink($outside . '/config.dev.json', $root . '/.sugar-crush/config.dev.json');
            }

            $this->assertFileExists(
                $root . '/.sugar-crush/config.dev.json',
                'the fixture is only meaningful while the file is REACHABLE — is_file() stats through a symlink',
            );
            $this->assertNull(ProviderFactory::readableDefaultConfigPath($root));
        } finally {
            @unlink($root . '/.sugar-crush/config.dev.json');
            @unlink($root . '/.sugar-crush');
            @rmdir($root . '/.sugar-crush');
            @unlink($outside . '/config.dev.json');
            @rmdir($outside);
            @rmdir($root);
            @rmdir($tmp);
        }
    }

    /**
     * THE CONTROL, twice over: a real `.sugar-crush/config.dev.json` inside a
     * synthetic package root is readable, and so is this library's own committed
     * one — which is what every launch actually reads, so a gate that refused it
     * would be a gate that broke the product.
     */
    public function testARealProviderConfigInsideThePackageIsReadable(): void
    {
        $tmp = sys_get_temp_dir() . '/pf_containment_ok_' . uniqid('', true);
        mkdir($tmp . '/package/.sugar-crush', 0o700, true);
        file_put_contents($tmp . '/package/.sugar-crush/config.dev.json', '{}');

        try {
            $this->assertSame(
                $tmp . '/package/.sugar-crush/config.dev.json',
                ProviderFactory::readableDefaultConfigPath($tmp . '/package'),
            );
        } finally {
            @unlink($tmp . '/package/.sugar-crush/config.dev.json');
            @rmdir($tmp . '/package/.sugar-crush');
            @rmdir($tmp . '/package');
            @rmdir($tmp);
        }

        $this->assertSame(
            ProviderFactory::defaultConfigPath(),
            ProviderFactory::readableDefaultConfigPath(),
            "this library's own committed config must still be readable",
        );
    }

    /**
     * Reproduces R24: loads the ACTUAL committed .sugar-crush/config.dev.json
     * (not a fixture stand-in) via defaultConfigPath(), with no explicit
     * $name, and asserts the 'defaultProvider' key ('dev-sglang') resolves
     * to a real, working SglangProvider wired from that file's own
     * baseUrl/model.
     *
     * The "expected" values are read from an INDEPENDENTLY-computed
     * in-library path (not from defaultConfigPath() itself) so this can't
     * pass by tautologically comparing the method's result to itself - it
     * only passes if fromProjectConfig() actually loaded the file that
     * lives inside this library.
     */
    public function testFromProjectConfigLoadsRealConfigDevJsonDefaultProvider(): void
    {
        $libraryScopedConfigPath = dirname(__DIR__, 2) . '/.sugar-crush/config.dev.json';
        $this->assertFileExists($libraryScopedConfigPath, 'Expected the committed .sugar-crush/config.dev.json to exist inside this library');

        $raw = json_decode(file_get_contents($libraryScopedConfigPath), true);
        $this->assertIsArray($raw);
        $this->assertSame('dev-sglang', $raw['defaultProvider']);

        $this->assertSame(
            realpath($libraryScopedConfigPath),
            realpath(ProviderFactory::defaultConfigPath()),
            'fromProjectConfig() default path must be this exact in-library file'
        );

        $provider = $this->factory->fromProjectConfig();

        $this->assertInstanceOf(SglangProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('sglang', $provider->name());

        $expected = $raw['providers']['dev-sglang'];
        $this->assertSame($expected['baseUrl'], $this->sglangPropertyOf($provider, 'baseUrl'));
        $this->assertSame($expected['model'], $this->sglangPropertyOf($provider, 'model'));
    }

    /**
     * Reproduces R24 finding #1: reviewer's exact hand-tested repro was
     * `$factory->create($factory->defaultConfig(getenv('SUGARCRUSH_PROVIDER')))`
     * with SUGARCRUSH_PROVIDER=dev-sglang - the precise call chain
     * bin/sugarcrush's $SUGARCRUSH_PROVIDER selection uses (bin/sugarcrush
     * lines 61-66). Before the R24.fix, defaultConfig('dev-sglang') threw
     * "Unknown provider type: dev-sglang" because it only recognized the
     * seven built-in TYPE_SCHEMAS types, so this exact path never reached
     * fromProjectConfig() at all and bin/sugarcrush silently fell back to
     * the offline EchoProvider. defaultConfig() must now resolve
     * 'dev-sglang' via the project's real, committed
     * .sugar-crush/config.dev.json so this reproduction succeeds without
     * bin/sugarcrush needing any change or any awareness of
     * fromProjectConfig().
     */
    public function testDefaultConfigAndCreateSelectDevSglangLikeBinSugarcrushDoes(): void
    {
        $config = $this->factory->defaultConfig('dev-sglang');

        $this->assertSame('sglang', $config['type']);

        $provider = $this->factory->create($config);

        $this->assertInstanceOf(SglangProvider::class, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('sglang', $provider->name());

        $configPath = ProviderFactory::defaultConfigPath();
        $raw = json_decode(file_get_contents($configPath), true);
        $expected = $raw['providers']['dev-sglang'];

        $this->assertSame($expected['baseUrl'], $this->sglangPropertyOf($provider, 'baseUrl'));
        $this->assertSame($expected['model'], $this->sglangPropertyOf($provider, 'model'));
    }

    /**
     * bin/sugarcrush also reads `$factory->defaultConfig($providerType)['model']`
     * as the $SUGARCRUSH_MODEL env-var fallback (bin/sugarcrush line 64) -
     * confirm that resolves to the project config's own model too, not just
     * provider construction.
     */
    public function testDefaultConfigModelFallbackResolvesFromProjectConfigForDevSglang(): void
    {
        $config = $this->factory->defaultConfig('dev-sglang');

        $this->assertSame('Qwen/Qwen3.8-Flash-Next', $config['model']);
        // The DISCRIMINATOR, kept belt-and-braces. Post-Q1 the two sources
        // name DIFFERENT models (built-in schema = SglangProvider::DEFAULT_MODEL,
        // a DeepSeek id; project config = the Qwen id asserted above), so the
        // model assertion alone now distinguishes them - which is the opposite
        // of the pre-Q1 situation this comment used to describe. `baseUrl`
        // keeps the proof independent of any future model-default flip: the
        // built-in schema says http://localhost:30000 and only the project
        // config says skynet2.
        $this->assertSame('https://skynet2.interserver.net/v1', $config['baseUrl']);
        $this->assertNotSame('http://localhost:30000', $config['baseUrl']);
    }

    /**
     * A name that is neither a built-in type nor declared in the project's
     * config.dev.json must still throw exactly as before - the project-config
     * fallback must not swallow genuinely-unknown types.
     */
    public function testDefaultConfigStillThrowsForNameAbsentFromBothSchemaAndProjectConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown provider type: totally-unknown-provider');

        $this->factory->defaultConfig('totally-unknown-provider');
    }

    public function testFromProjectConfigSelectsProviderByNameNotJustDefault(): void
    {
        $provider = $this->factory->fromProjectConfig('dev-sglang');

        $this->assertInstanceOf(SglangProvider::class, $provider);
        $this->assertSame('sglang', $provider->name());
    }

    public function testFromProjectConfigThrowsWhenFileMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider config file not found');

        $this->factory->fromProjectConfig(configPath: '/nonexistent/.sugar-crush/config.dev.json');
    }

    public function testFromProjectConfigThrowsWhenDefaultProviderKeyMissing(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pf_cfg_');
        file_put_contents($tmp, json_encode([
            'providers' => [
                'dev-sglang' => ['type' => 'sglang', 'baseUrl' => 'http://localhost:30000', 'model' => 'm'],
            ],
        ]));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("missing a 'defaultProvider' key");

            $this->factory->fromProjectConfig(configPath: $tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testFromProjectConfigThrowsWhenNamedProviderEntryMissing(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pf_cfg_');
        file_put_contents($tmp, json_encode([
            'providers' => [
                'dev-sglang' => ['type' => 'sglang', 'baseUrl' => 'http://localhost:30000', 'model' => 'm'],
            ],
            'defaultProvider' => 'dev-sglang',
        ]));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("no 'providers.missing-provider' entry");

            $this->factory->fromProjectConfig('missing-provider', configPath: $tmp);
        } finally {
            unlink($tmp);
        }
    }

    /**
     * Reads a private scalar property off a SglangProvider instance, to
     * assert the provider was actually built from the config file's own
     * baseUrl/model rather than some hardcoded default.
     */
    private function sglangPropertyOf(SglangProvider $provider, string $name): mixed
    {
        $prop = (new \ReflectionClass(SglangProvider::class))->getProperty($name);
        $prop->setAccessible(true);

        return $prop->getValue($provider);
    }

    // -------------------------------------------------------------------------
    // createSglang() - W1.A6 (§12 D6) tool-call-parser selection
    // -------------------------------------------------------------------------

    /**
     * §12 D6's documented default: a config with no `toolCallParser` key gets
     * the OpenAI-array strategy, matching the confirmed live deployment (which
     * does pass `--tool-call-parser minimax-m2`, so the server has already
     * decoded the call by the time it reaches us).
     */
    public function testCreateSglangDefaultsToTheOpenAiArrayToolCallParser(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
        ]);

        $this->assertInstanceOf(
            OpenAiArrayToolCallParser::class,
            $this->toolCallParserOf($provider),
        );
    }

    public function testCreateSglangHonoursAnExplicitOpenaiToolCallParserName(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
            'toolCallParser' => 'openai',
        ]);

        $this->assertInstanceOf(
            OpenAiArrayToolCallParser::class,
            $this->toolCallParserOf($provider),
        );
    }

    public function testCreateSglangSelectsTheMinimaxXmlFallbackParserFromConfig(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
            'toolCallParser' => 'minimax-xml-fallback',
        ]);

        $this->assertInstanceOf(
            MinimaxXmlFallbackToolCallParser::class,
            $this->toolCallParserOf($provider),
        );
    }

    /**
     * The behavioural half of §12 D6, and the case that fails outright against
     * the pre-W1.A6 factory: before this step nothing ever constructed either
     * parser class, so a `toolCallParser` key was ignored and a deployment
     * launched WITHOUT `--tool-call-parser` - which delivers the call as
     * literal XML in `content`, with no `tool_calls` array - lost the call
     * entirely. Driving the factory-built parser directly proves the recovery
     * path is wired, not merely that a class of the right type was stored.
     */
    public function testFactoryBuiltMinimaxFallbackParserRecoversAnXmlOnlyToolCall(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
            'toolCallParser' => 'minimax-xml-fallback',
        ]);

        $calls = $this->toolCallParserOf($provider)->parse([
            'content' => '<minimax:tool_call><invoke name="read_file">'
                . '<parameter name="path">/etc/hosts</parameter>'
                . '</invoke></minimax:tool_call>',
        ]);

        $this->assertIsArray($calls);
        $this->assertCount(1, $calls);
        $this->assertSame('read_file', $calls[0]->name());
        $this->assertSame(['path' => '/etc/hosts'], $calls[0]->arguments());
    }

    /**
     * The default parser must still be handed SglangProvider's own
     * truncation-aware argument decoder (§12 D5) - selecting a parser from
     * config must not quietly cost the truncation diagnostics.
     *
     * Asserted behaviourally rather than by type: OpenAiArrayToolCallParser
     * falls back to a plain closure when handed null, so
     * `assertInstanceOf(\Closure::class, ...)` on the injected decoder passes
     * either way and would not catch a regression to
     * `OpenAiArrayToolCallParser::new()`. Driving a truncated payload through
     * and demanding the warning does.
     */
    public function testFactoryBuiltParserUsesSglangsTruncationAwareArgumentDecoder(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
        ]);

        $logFile = sys_get_temp_dir() . '/factory-parser-decoder-' . uniqid('', true) . '.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $calls = $this->toolCallParserOf($provider)->parse([
                'tool_calls' => [[
                    'id' => 'call_trunc',
                    'function' => [
                        'name' => 'write_file',
                        // The §12 D5 signature: the value stops the instant the
                        // model emitted a literal '</parameter>' inside it.
                        'arguments' => '{"content":"<x</parameter>',
                    ],
                ]],
            ]);
            $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($logFile);
        }

        $this->assertIsArray($calls);
        $this->assertCount(1, $calls);
        $this->assertSame([], $calls[0]->arguments());
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $log);
        $this->assertStringContainsString('write_file', $log);
    }

    /**
     * `''` is not an operator typo, so it must not hit the throw: it is what
     * ProviderFactory::resolveEnvVars() produces for a
     * `${SUGARCRUSH_TOOL_CALL_PARSER}` placeholder whose variable is unset,
     * i.e. the key being absent.
     */
    public function testCreateSglangTreatsAnEmptyToolCallParserNameAsUnset(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
            'toolCallParser' => '',
        ]);

        $this->assertInstanceOf(
            OpenAiArrayToolCallParser::class,
            $this->toolCallParserOf($provider),
        );
    }

    /**
     * A typo'd parser name must throw, not silently fall back to the default:
     * an operator who wrote `minimax-xml` would otherwise believe the fallback
     * is armed when it is not.
     */
    public function testCreateSglangThrowsOnAnUnknownToolCallParserName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown toolCallParser: minimax-xml');

        $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
            'toolCallParser' => 'minimax-xml',
        ]);
    }

    public function testCreateSglangSelectsTheDsmlParserFromConfig(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            // A NON-DeepSeek model on purpose: this pins the explicit name,
            // not the model-derived default, so the two mechanisms cannot be
            // confused for one another.
            'model' => 'MiniMax-M2.7',
            'toolCallParser' => ProviderFactory::TOOL_CALL_PARSER_DSML,
        ]);

        $this->assertInstanceOf(DsmlToolCallParser::class, $this->toolCallParserOf($provider));
    }

    /**
     * The model-derived default, asserted in BOTH directions from one method
     * so a change that collapses the two arms cannot pass.
     *
     * DeepSeek-V4 gets DSML armed because that family's card documents a
     * launch command with no `--tool-call-parser` flag, and without the
     * fallback every tool call on such a deployment is lost in silence.
     * Everything else keeps the OpenAI-only parser, so no MiniMax deployment's
     * behaviour moves.
     *
     * @dataProvider defaultParserByModelProvider
     */
    public function testTheUnnamedToolCallParserIsDerivedFromTheModel(string $model, string $expected): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => $model,
        ]);

        $this->assertInstanceOf($expected, $this->toolCallParserOf($provider));
    }

    public static function defaultParserByModelProvider(): array
    {
        return [
            'deployed DeepSeek id' => [SglangProvider::DEFAULT_MODEL, DsmlToolCallParser::class],
            // The family predicate deliberately over-matches, so a redeploy
            // that only changes the dated suffix or the point release still
            // takes the DSML arm.
            'DeepSeek point release' => ['deepseek-ai/DeepSeek-V4.5', DsmlToolCallParser::class],
            'MiniMax' => ['MiniMax-M2.7', OpenAiArrayToolCallParser::class],
            'an unrelated model' => ['qwen3-coder', OpenAiArrayToolCallParser::class],
        ];
    }

    /**
     * The error message must enumerate EVERY name the factory accepts.
     *
     * It used to read `'expected "%s" or "%s"'` with exactly two arguments, so
     * adding a third parser without touching it would have printed an error
     * omitting the very name the operator was trying to spell. Asserting
     * against {@see ProviderFactory::TOOL_CALL_PARSER_NAMES} rather than a
     * hand-typed list is what makes this catch the NEXT parser too.
     */
    public function testTheUnknownParserMessageEnumeratesEverySelectableName(): void
    {
        try {
            $this->factory->create([
                'type' => 'sglang',
                'baseUrl' => 'http://localhost:30000',
                'model' => 'MiniMax-M2.7',
                'toolCallParser' => 'nope',
            ]);

            $this->fail('an unknown parser name must throw');
        } catch (\InvalidArgumentException $e) {
            foreach (ProviderFactory::TOOL_CALL_PARSER_NAMES as $name) {
                $this->assertStringContainsString(
                    sprintf('"%s"', $name),
                    $e->getMessage(),
                    sprintf('the error omits the selectable parser "%s"', $name),
                );
            }
        }
    }

    /**
     * The other half of the same guarantee, and the one that catches a name
     * added to the constant but never given a `match` arm - which would throw
     * "Unknown toolCallParser" for a name the error message itself advertises.
     *
     * @dataProvider selectableToolCallParserNameProvider
     */
    public function testEverySelectableToolCallParserNameActuallyConstructs(string $name): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'MiniMax-M2.7',
            'toolCallParser' => $name,
        ]);

        $this->assertInstanceOf(ToolCallParserInterface::class, $this->toolCallParserOf($provider));
    }

    public static function selectableToolCallParserNameProvider(): array
    {
        return array_map(
            static fn (string $name): array => [$name],
            array_combine(ProviderFactory::TOOL_CALL_PARSER_NAMES, ProviderFactory::TOOL_CALL_PARSER_NAMES),
        );
    }

    /**
     * `defaultConfig('sglang')` declares the KNOB but not a VALUE, and the
     * difference is the whole point.
     *
     * This used to assert `'openai'`. That was correct while `openai` was the
     * unconditional default; it stopped being correct when the default grew a
     * second, model-derived arm (`dsml` for the DeepSeek-V4 family). A stamped
     * literal would keep selecting the OpenAI-only parser after an operator
     * edits `model`, silently disarming the DSML fallback on exactly the
     * family that needs it - the same decay `reasoningEffort` is null to
     * avoid.
     *
     * The key must still be PRESENT: `defaultConfig()` output is what the
     * Ctrl+P palette's Switch Model listing shows, so dropping it would hide
     * the knob.
     */
    public function testDefaultConfigSglangDeclaresTheToolCallParserKnobWithoutStampingAValue(): void
    {
        $config = $this->factory->defaultConfig('sglang');

        $this->assertArrayHasKey('toolCallParser', $config);
        $this->assertNull($config['toolCallParser']);
    }

    /**
     * The TRUTH behind the null above: feeding `defaultConfig('sglang')`
     * straight back into `create()` must arm the DSML parser, because that
     * config's own `model` is a DeepSeek-V4 id.
     *
     * This is the assertion the old `assertSame('openai', ...)` could not
     * make. It pins the round trip - default config in, correct parser out -
     * rather than the spelling of one field, so it stays honest if the
     * defaulting mechanism is rewritten.
     */
    public function testDefaultConfigSglangRoundTripsIntoTheDsmlParserForItsOwnDefaultModel(): void
    {
        $config = $this->factory->defaultConfig('sglang');

        $this->assertTrue(
            SglangProvider::isDeepSeekV4((string) $config['model']),
            'the sglang default model is expected to be a DeepSeek-V4 id',
        );

        $this->assertInstanceOf(
            DsmlToolCallParser::class,
            $this->toolCallParserOf($this->factory->create($config)),
        );
    }

    /**
     * Reads the tool-call parser the factory injected into a SglangProvider.
     */
    private function toolCallParserOf(ProviderInterface $provider): ToolCallParserInterface
    {
        $this->assertInstanceOf(SglangProvider::class, $provider);

        $parser = $this->sglangPropertyOf($provider, 'toolCallParser');

        $this->assertInstanceOf(ToolCallParserInterface::class, $parser);

        return $parser;
    }

    // -------------------------------------------------------------------------
    // The optional `reasoningEffort` provider-block key.
    //
    // A CONFIG KEY was chosen over a bespoke env var because the placeholder
    // syntax already works in every config value - `"${SUGARCRUSH_EFFORT}"` in
    // this key gets env-var support for free, so a second mechanism would only
    // add a second thing to keep in sync. The '' case below is exactly the
    // seam that buys.
    // -------------------------------------------------------------------------

    public function testConfiguredReasoningEffortReachesTheProvider(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'reasoningEffort' => 'high',
        ]);

        $this->assertSame('high', $this->sglangPropertyOf($provider, 'reasoningEffort'));
    }

    public function testAbsentReasoningEffortLeavesTheProviderOnItsModelDerivedDefault(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
        ]);

        // Null on the PROVIDER, not 'max'. The 'max' lives in
        // SglangProvider::defaultReasoningEffort(), keyed on the model, so an
        // operator who later edits `model` to a MiniMax id stops sending an
        // effort instead of shipping a DeepSeek-measured one to MiniMax.
        $this->assertNull($this->sglangPropertyOf($provider, 'reasoningEffort'));
    }

    public function testAnUnresolvedEnvPlaceholderForReasoningEffortIsTreatedAsAbsent(): void
    {
        putenv('SUGARCRUSH_TEST_EFFORT');

        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            // resolveEnvVars() yields '' for an unset variable. That is the key
            // being ABSENT, not misspelled, so it must not throw - the same
            // exception toolCallParser() makes for the same mechanism.
            'reasoningEffort' => '${SUGARCRUSH_TEST_EFFORT}',
        ]);

        $this->assertNull($this->sglangPropertyOf($provider, 'reasoningEffort'));
    }

    public function testAResolvedEnvPlaceholderForReasoningEffortIsUsed(): void
    {
        putenv('SUGARCRUSH_TEST_EFFORT=xhigh');

        try {
            $provider = $this->factory->create([
                'type' => 'sglang',
                'baseUrl' => 'http://localhost:30000',
                'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
                'reasoningEffort' => '${SUGARCRUSH_TEST_EFFORT}',
            ]);

            $this->assertSame('xhigh', $this->sglangPropertyOf($provider, 'reasoningEffort'));
        } finally {
            putenv('SUGARCRUSH_TEST_EFFORT');
        }
    }

    public function testAMisspelledConfiguredReasoningEffortThrowsWhenTheProviderIsBuilt(): void
    {
        // Fails at BUILD time, not on the first completion: a typo in a config
        // file should not survive until someone sends a message.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('provider config');

        $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'reasoningEffort' => 'ultra',
        ]);
    }

    public function testANonScalarConfiguredReasoningEffortIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('level name or a number');

        $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'reasoningEffort' => ['max'],
        ]);
    }

    /**
     * WHY THE INT->FLOAT CAST EXISTS: `json_decode` yields an INT for a JSON
     * whole number, the DTO field is `string|float|null`, and `0.0` is a value
     * the server accepts (measured 200 on 2026-08-20). Without the cast
     * `"reasoningEffort": 0` would TypeError before any request went out.
     *
     * Asserted with a strict `assertSame(0.0, ...)` so the float-ness is the
     * claim, not merely the numeric value - `0` would satisfy a loose compare.
     */
    public function testAWholeNumberZeroConfiguredEffortBecomesTheFloatTheServerAccepts(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'reasoningEffort' => 0,
        ]);

        $this->assertSame(0.0, $this->sglangPropertyOf($provider, 'reasoningEffort'));
    }

    /**
     * THE COST OF THAT SAME CAST, pinned so it cannot be mistaken for a
     * guarantee it is not.
     *
     * `"reasoningEffort": 1` becomes `1.0`, which is the ONE float just outside
     * SGLang's bound - measured 2026-08-20, the server answers HTTP 400 naming
     * `le: 0.99`, and it does so on every completion rather than here. So the
     * construction-time refusal that a misspelled LEVEL NAME gets (see
     * testAMisspelledConfiguredReasoningEffortThrowsWhenTheProviderIsBuilt)
     * does NOT extend to numbers, deliberately: the level names are a closed
     * pydantic literal, while the float bound is a server-side constraint a
     * later SGLang may widen, and hardcoding 0.99 here would refuse whatever it
     * widens to.
     *
     * This test therefore asserts a KNOWN-BAD value is accepted locally. That
     * is the behaviour, and the README documents "write 0.99, not 1" because of
     * it. If a range check is ever added, this test is where the decision
     * changes - it fails rather than silently becoming vacuous.
     */
    public function testAWholeNumberOneIsAcceptedLocallyEvenThoughTheServerRefusesIt(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'reasoningEffort' => 1,
        ]);

        $this->assertSame(1.0, $this->sglangPropertyOf($provider, 'reasoningEffort'));
    }

    // -------------------------------------------------------------------------
    // The optional `templateKwargs` provider-block key (Q3).
    //
    // Deployment-wide `chat_template_kwargs` - the same value surface the
    // per-request DTO carries, set once in config. SHAPE is validated here at
    // parse time (associative array of string keys) while VALUES pass through
    // unchecked: what a server-side Jinja template accepts is the server's
    // business (pydantic answers with a 400), not a closed enum the way the
    // effort levels are. resolveEnvVars() already recurses into array values,
    // so `${VAR}` placeholders inside kwargs STRINGS ride the existing
    // expansion - the test below pins that rather than a new mechanism.
    // -------------------------------------------------------------------------

    public function testConfiguredTemplateKwargsReachTheProvider(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'templateKwargs' => ['enable_thinking' => true, 'preserve_thinking' => false],
        ]);

        $this->assertSame(
            ['enable_thinking' => true, 'preserve_thinking' => false],
            $this->sglangPropertyOf($provider, 'extraTemplateKwargs'),
        );
    }

    public function testAnEmptyTemplateKwargsObjectIsAcceptedAsNone(): void
    {
        // The committed config.dev.json carries `"templateKwargs": {}` as a
        // discoverability placeholder; accepting it as exactly what absent
        // means is what keeps that placeholder behavior-neutral.
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'templateKwargs' => [],
        ]);

        $this->assertSame([], $this->sglangPropertyOf($provider, 'extraTemplateKwargs'));
    }

    public function testAbsentTemplateKwargsLeaveTheProviderWithNone(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
        ]);

        $this->assertSame([], $this->sglangPropertyOf($provider, 'extraTemplateKwargs'));
    }

    public function testEnvPlaceholdersInsideTemplateKwargsValuesResolveThroughTheExistingExpansion(): void
    {
        putenv('SUGARCRUSH_TEST_KWARG_STYLE=concise');

        try {
            $provider = $this->factory->create([
                'type' => 'sglang',
                'baseUrl' => 'http://localhost:30000',
                'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
                'templateKwargs' => ['style' => '${SUGARCRUSH_TEST_KWARG_STYLE}'],
            ]);

            $this->assertSame(
                ['style' => 'concise'],
                $this->sglangPropertyOf($provider, 'extraTemplateKwargs'),
            );
        } finally {
            putenv('SUGARCRUSH_TEST_KWARG_STYLE');
        }
    }

    public function testANonArrayStringConfiguredTemplateKwargsIsRejected(): void
    {
        // Rejected at BUILD time from the config-parse seam, naming the key
        // and the expected shape - the configuredReasoningEffort polarity.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('templateKwargs must be an associative array, got string');

        $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'templateKwargs' => 'enable_thinking',
        ]);
    }

    public function testAScalarNumberConfiguredTemplateKwargsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('templateKwargs must be an associative array, got int');

        $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'templateKwargs' => 7,
        ]);
    }

    public function testAnIntegerKeyedListConfiguredTemplateKwargsIsRejected(): void
    {
        // `["enable_thinking"]` decodes to an int-keyed PHP list. It is not a
        // map of template parameters, and `get_debug_type` would say `array`
        // for it, so the message names the offending key instead.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('templateKwargs must be an associative array, got an integer key at offset 0');

        $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'templateKwargs' => ['enable_thinking'],
        ]);
    }

    /**
     * The MiniMax XML fallback parser must stay selectable and functional on a
     * provider whose model is the NEW default.
     *
     * This is the "keep both working" constraint expressed as a test: making
     * DeepSeek-V4 the default must not gate MiniMax handling behind the model
     * name. Nothing in the parser selection reads the model, and this asserts
     * that rather than assuming it - a future "optimisation" that skips the
     * fallback when the model is not MiniMax would fail here.
     */
    public function testTheMinimaxXmlFallbackStaysReachableOnADeepSeekModelledProvider(): void
    {
        $provider = $this->factory->create([
            'type' => 'sglang',
            'baseUrl' => 'http://localhost:30000',
            'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731',
            'toolCallParser' => 'minimax-xml-fallback',
        ]);

        $calls = $this->toolCallParserOf($provider)->parse([
            'content' => '<minimax:tool_call><invoke name="read_file">'
                . '<parameter name="path">/etc/hosts</parameter>'
                . '</invoke></minimax:tool_call>',
        ]);

        $this->assertIsArray($calls);
        $this->assertCount(1, $calls);
        $this->assertSame('read_file', $calls[0]->name());
        $this->assertSame(['path' => '/etc/hosts'], $calls[0]->arguments());
    }

    /**
     * THE DIRECTION THE OTHER TWO TESTS CANNOT SEE, and the one a mutation
     * survived.
     *
     * Deleting the line `self::TOOL_CALL_PARSER_DSML,` from
     * {@see ProviderFactory::TOOL_CALL_PARSER_NAMES} left every existing test
     * green: `testEverySelectableToolCallParserNameActuallyConstructs` iterates
     * the constant, so a name removed from it is simply never tried, and
     * `testTheUnknownParserMessageEnumeratesEverySelectableName` asserts the
     * message lists everything in the constant, which a shorter constant still
     * satisfies. The `match` went on accepting `dsml` while the error message
     * stopped advertising it - drift caught in one direction only.
     *
     * A previous report conceded that PHP cannot mechanically derive the
     * constant from the `match` arms. That is true of the LANGUAGE and false
     * of the SOURCE, which is readable: this asserts set EQUALITY between the
     * `self::TOOL_CALL_PARSER_*` constants the method body actually mentions
     * and the ones the constant lists. A name in the constant with no arm
     * fails it; an arm for a name the constant omits fails it too.
     */
    public function testTheSelectableNameListAndTheMatchArmsReferenceExactlyTheSameConstants(): void
    {
        $method = new \ReflectionMethod(ProviderFactory::class, 'toolCallParser');
        $lines = (array) file((string) $method->getFileName());
        $body = implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        preg_match_all('/self::(TOOL_CALL_PARSER_[A-Z0-9_]+)/', $body, $matches);

        $referenced = array_values(array_diff(array_unique($matches[1]), ['TOOL_CALL_PARSER_NAMES']));

        $this->assertNotEmpty($referenced, 'the match arms must be readable, or this test proves nothing');

        $armValues = array_map(
            static fn (string $constant): mixed => \constant(ProviderFactory::class . '::' . $constant),
            $referenced,
        );

        sort($armValues);
        $listed = ProviderFactory::TOOL_CALL_PARSER_NAMES;
        sort($listed);

        $this->assertSame(
            $listed,
            $armValues,
            'TOOL_CALL_PARSER_NAMES and the match arms of toolCallParser() must name the same parsers',
        );
    }

    /**
     * The same correspondence from the other end, without reading any source:
     * a `TOOL_CALL_PARSER_*` constant that exists but is not listed is a
     * parser the operator can select and the error message will deny.
     */
    public function testEveryDeclaredParserNameConstantIsListedAsSelectable(): void
    {
        $declared = [];

        foreach ((new \ReflectionClass(ProviderFactory::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'TOOL_CALL_PARSER_') && $name !== 'TOOL_CALL_PARSER_NAMES') {
                $declared[$name] = $value;
            }
        }

        $this->assertNotEmpty($declared);

        foreach ($declared as $name => $value) {
            $this->assertContains(
                $value,
                ProviderFactory::TOOL_CALL_PARSER_NAMES,
                sprintf('%s is declared but missing from TOOL_CALL_PARSER_NAMES', $name),
            );
        }

        $this->assertCount(
            count($declared),
            ProviderFactory::TOOL_CALL_PARSER_NAMES,
            'TOOL_CALL_PARSER_NAMES must list every declared name and no others',
        );
    }
}
