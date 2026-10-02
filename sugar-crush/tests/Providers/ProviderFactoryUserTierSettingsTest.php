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
    // Fixtures
    // -------------------------------------------------------------------------

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
