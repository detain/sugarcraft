<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The user-tier settings {@see ProviderFactory} threads into the providers it
 * builds, read through `Bootstrap::readUserConfig()` against a SANDBOXED home
 * so the operator's real `~/.sugar-crush` never decides a result.
 *
 * Audit A15/A20: `modelPrices` reached only the OpenAI provider, although
 * Vertex and Bedrock both have a price table it overrides; and the default
 * `bedrock` config still sent the bare foundation-model id.
 */
final class ProviderFactoryUserTierSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $configDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/provider_user_tier_' . uniqid('', true);
        $home = $this->tmpDir . '/home';
        $this->configDir = $home . '/.sugar-crush';
        mkdir($this->configDir, 0o700, true);

        $this->useHomeSandbox($home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        $this->removeTree($this->tmpDir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // A15: the user's modelPrices reaches Vertex and Bedrock
    // -------------------------------------------------------------------------

    public function testTheUserTierModelPricesReachVertex(): void
    {
        $this->writeUserConfig(['modelPrices' => ['claude-made-up-4' => ['input' => 3, 'output' => 15]]]);

        $provider = (new ProviderFactory())->create([
            'type' => 'vertex',
            'projectId' => 'p',
            'model' => 'claude-made-up-4',
        ]);

        self::assertInstanceOf(VertexProvider::class, $provider);
        self::assertSame(0.003, $provider->costPer1kTokens('claude-made-up-4', 'input'));
        self::assertSame(0.015, $provider->costPer1kTokens('claude-made-up-4', 'output'));
    }

    public function testTheUserTierModelPricesReachBedrock(): void
    {
        $this->writeUserConfig(['modelPrices' => ['us.meta.made-up' => ['input' => 1, 'output' => 2]]]);

        $provider = (new ProviderFactory())->create(['type' => 'bedrock', 'region' => 'us-east-1']);

        self::assertInstanceOf(BedrockProvider::class, $provider);
        self::assertSame(0.001, $provider->costPer1kTokens('us.meta.made-up', 'input'));
        self::assertSame(0.002, $provider->costPer1kTokens('us.meta.made-up', 'output'));
    }

    /** The provider block's own map is the narrower statement and wins outright. */
    public function testAProviderBlockModelPricesOutranksTheUserTier(): void
    {
        $this->writeUserConfig(['modelPrices' => ['claude-made-up-4' => ['input' => 3, 'output' => 15]]]);

        $provider = (new ProviderFactory())->create([
            'type' => 'vertex',
            'projectId' => 'p',
            'model' => 'claude-made-up-4',
            'modelPrices' => ['claude-made-up-4' => ['input' => 5, 'output' => 25]],
        ]);

        self::assertSame(0.005, $provider->costPer1kTokens('claude-made-up-4', 'input'));
    }

    /** With nothing declared, an unknown model stays UNPRICED, never zero-priced. */
    public function testWithNoDeclarationAnUnknownModelStaysUnpriced(): void
    {
        $provider = (new ProviderFactory())->create(['type' => 'bedrock', 'region' => 'us-east-1']);

        self::assertNull($provider->costPer1kTokens('us.meta.made-up', 'input'));
    }

    // -------------------------------------------------------------------------
    // A20: the default bedrock config sends the inference-profile id
    // -------------------------------------------------------------------------

    public function testTheDefaultBedrockConfigNamesTheInferenceProfileId(): void
    {
        $config = (new ProviderFactory())->defaultConfig('bedrock');

        self::assertSame(BedrockProvider::DEFAULT_MODEL, $config['model']);
        self::assertStringStartsWith('us.', $config['model']);
        self::assertGreaterThanOrEqual(200_000, (new ProviderFactory())->create($config)->contextWindow());
    }

    // -------------------------------------------------------------------------
    // A13: contextWindow sizes the OpenAI provider
    // -------------------------------------------------------------------------

    public function testANumericContextWindowSizesWhateverModelOpenAiRuns(): void
    {
        $this->writeUserConfig(['contextWindow' => 400_000]);

        self::assertSame(400_000, $this->openAi('gpt-5-made-up')->contextWindow());
        // It replaces the built-in table too: the operator's figure wins.
        self::assertSame(400_000, $this->openAi('gpt-4o')->contextWindow());
    }

    public function testAPerModelContextWindowLeavesAnUnnamedModelOnTheTable(): void
    {
        $this->writeUserConfig(['contextWindow' => ['gpt-5-made-up' => 400_000]]);

        self::assertSame(400_000, $this->openAi('gpt-5-made-up')->contextWindow());
        self::assertSame(128_000, $this->openAi('gpt-4o')->contextWindow());
        self::assertSame(0, $this->openAi('another-unknown')->contextWindow());
    }

    /**
     * @dataProvider nonsenseWindows
     */
    public function testANonsenseContextWindowIsNoOverride(mixed $value): void
    {
        $this->writeUserConfig(['contextWindow' => $value]);

        self::assertSame(0, $this->openAi('gpt-5-made-up')->contextWindow());
    }

    /** @return array<string, array{mixed}> */
    public static function nonsenseWindows(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'fraction' => [1.5],
            'too large for an int' => [1e19],
            'word' => ['lots'],
            'list' => [[400_000]],
        ];
    }

    public function testAProviderBlockContextWindowOutranksTheUserTier(): void
    {
        $this->writeUserConfig(['contextWindow' => 400_000]);

        $provider = (new ProviderFactory())->create([
            'type' => 'openai',
            'apiKey' => 'k',
            'model' => 'gpt-5-made-up',
            'contextWindow' => '200000',
        ]);

        self::assertSame(200_000, $provider->contextWindow());
    }

    /**
     * USER-TIER ONLY: a trusted project's settings file cannot size the
     * window - an inflated one would switch auto-compaction off.
     */
    public function testATrustedProjectCannotSetTheProviderShapingKeys(): void
    {
        $root = $this->tmpDir . '/repo';
        mkdir($root . '/.sugar-crush', 0o700, true);
        $canonical = realpath($root);
        self::assertIsString($canonical);
        $this->writeUserConfig(['trustedProjectSettings' => [$canonical]]);
        file_put_contents($root . '/.sugar-crush/settings.json', (string) json_encode([
            'contextWindow' => 5_000_000,
            'thinkingBudget' => 30_000,
            'extraBody' => ['n' => 50],
            'theme' => 'from-project',
        ]));
        Bootstrap::useProjectRootForSettings($canonical);

        // The project layer IS read (its theme lands), so the absence below
        // is the tier filter rather than an unread file.
        self::assertSame('from-project', Bootstrap::readUserConfig()['theme'] ?? null);
        self::assertSame(0, $this->openAi('gpt-5-made-up')->contextWindow());
        self::assertNull($this->privateValue($this->vertex(), 'thinkingBudget'));
        self::assertSame([], $this->privateValue($this->custom(), 'extraBody'));
    }

    // -------------------------------------------------------------------------
    // A10: extraBody reaches the custom provider
    // -------------------------------------------------------------------------

    public function testTheUserTierExtraBodyReachesTheCustomProvider(): void
    {
        $this->writeUserConfig(['extraBody' => ['separate_reasoning' => true]]);

        self::assertSame(['separate_reasoning' => true], $this->privateValue($this->custom(), 'extraBody'));
    }

    public function testAProviderBlockExtraBodyOutranksTheUserTier(): void
    {
        $this->writeUserConfig(['extraBody' => ['separate_reasoning' => true]]);

        $provider = (new ProviderFactory())->create([
            'type' => 'custom',
            'name' => 'c',
            'baseUrl' => 'http://127.0.0.1:9',
            'model' => 'm',
            'extraBody' => ['top_k' => 20],
        ]);

        self::assertSame(['top_k' => 20], $this->privateValue($provider, 'extraBody'));
    }

    /** The provider's own key refusal applies to the setting, loudly, naming the key. */
    public function testAnExtraBodyNamingAFieldTheProviderWritesIsRefused(): void
    {
        $this->writeUserConfig(['extraBody' => ['model' => 'smuggled']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"model"');

        $this->custom();
    }

    public function testANonObjectExtraBodyIsNoExtraBody(): void
    {
        $this->writeUserConfig(['extraBody' => 'separate_reasoning']);

        self::assertSame([], $this->privateValue($this->custom(), 'extraBody'));
    }

    // -------------------------------------------------------------------------
    // A21 (b): thinkingBudget reaches the Vertex provider
    // -------------------------------------------------------------------------

    public function testTheUserTierThinkingBudgetReachesVertex(): void
    {
        $this->writeUserConfig(['thinkingBudget' => 8192]);

        self::assertSame(8192, $this->privateValue($this->vertex(), 'thinkingBudget'));
    }

    public function testMinusOneMeansDynamicAndBelowThatIsNoSetting(): void
    {
        $this->writeUserConfig(['thinkingBudget' => -1]);
        self::assertSame(-1, $this->privateValue($this->vertex(), 'thinkingBudget'));

        $this->writeUserConfig(['thinkingBudget' => -7]);
        self::assertNull($this->privateValue($this->vertex(), 'thinkingBudget'));
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function openAi(string $model): \SugarCraft\Crush\Providers\OpenAIProvider
    {
        $provider = (new ProviderFactory())->create(['type' => 'openai', 'apiKey' => 'k', 'model' => $model]);
        self::assertInstanceOf(\SugarCraft\Crush\Providers\OpenAIProvider::class, $provider);

        return $provider;
    }

    private function vertex(): VertexProvider
    {
        $provider = (new ProviderFactory())->create(['type' => 'vertex', 'projectId' => 'p', 'model' => 'gemini-2.5-pro']);
        self::assertInstanceOf(VertexProvider::class, $provider);

        return $provider;
    }

    private function custom(): \SugarCraft\Crush\Providers\CustomProvider
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'custom',
            'name' => 'c',
            'baseUrl' => 'http://127.0.0.1:9',
            'model' => 'm',
        ]);
        self::assertInstanceOf(\SugarCraft\Crush\Providers\CustomProvider::class, $provider);

        return $provider;
    }

    /**
     * The constructed value, read off the provider: neither seam has a public
     * accessor, and the wire effect of each is pinned where the provider is
     * driven directly (VertexGeminiOutputBudgetTest, CustomProvider's own
     * extraBody tests).
     */
    private function privateValue(object $provider, string $property): mixed
    {
        return (new \ReflectionProperty($provider, $property))->getValue($provider);
    }

    /** @param array<string, mixed> $data */
    private function writeUserConfig(array $data): void
    {
        $path = $this->configDir . '/config.json';
        file_put_contents($path, (string) json_encode($data));
        chmod($path, 0o600);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
