<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\VertexProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-025: the Vertex factory default was `claude-3-sonnet@20240229`, a
 * retired model Anthropic decommissioned (2025-07-21) and Vertex has shut the
 * endpoint down - a fresh `CANDY_CODE_PROVIDER=vertex` install without an
 * explicit model failed every request. The default now names a current model
 * that resolves in the provider's own price table, and this guard pins both
 * halves for every provider that carries a built-in table:
 *
 *  1. every default-model site agrees (factory `defaultConfig`, the factory's
 *     fallback in `create*()`, and the provider's `create()` parameter), and
 *  2. that default resolves to a non-null rate in both directions, so a
 *     default can never report a fabricated $0.00 cost for a model the class
 *     claims to price.
 */
final class ProviderDefaultPriceTableTest extends TestCase
{
    use HomeSandboxTrait;

    private ProviderFactory $factory;

    private string $sandboxHome = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot FIRST, then the ENV_KEYS loop, then re-arm - the trait
        // contract (lane-cf law): an un-armed restore would leave HOME unset
        // and poison every later test in the process.
        $this->sandboxHome = sys_get_temp_dir() . '/provider_default_home_' . uniqid('', true);
        $this->useHomeSandbox($this->sandboxHome);
        $this->factory = new ProviderFactory();
        $this->useHomeSandbox($this->sandboxHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->sandboxHome);

        parent::tearDown();
    }

    public function testTheFactoryAndProviderAgreeOnTheVertexDefault(): void
    {
        $configDefault = $this->factory->defaultConfig('vertex')['model'];
        $providerDefault = VertexProvider::create(projectId: 'proj-price-table')->model();

        $this->assertIsString($configDefault);
        $this->assertSame((string) $configDefault, $providerDefault);

        // The factory's own fallback (a config array with no `model` key at
        // all) must land on the same id, not a fourth copy of an old one.
        $fromFactory = $this->factory->create(['type' => 'vertex', 'projectId' => 'proj-price-table']);
        $this->assertInstanceOf(VertexProvider::class, $fromFactory);
        $this->assertSame((string) $configDefault, $fromFactory->model());
    }

    public function testTheVertexDefaultIsNotTheRetiredClaude3Sonnet(): void
    {
        $model = VertexProvider::create(projectId: 'proj-price-table')->model();

        $this->assertStringNotContainsString('claude-3-sonnet', $model);
    }

    public function testTheVertexDefaultResolvesInItsPriceTable(): void
    {
        $provider = VertexProvider::create(projectId: 'proj-price-table');

        $this->assertNotNull($provider->costPer1kTokens($provider->model(), 'input'));
        $this->assertNotNull($provider->costPer1kTokens($provider->model(), 'output'));
    }

    public function testTheBedrockDefaultResolvesInItsPriceTable(): void
    {
        $provider = new BedrockProvider($this->offlineBedrockClient());

        $this->assertSame(BedrockProvider::DEFAULT_MODEL, $provider->model());
        $this->assertNotNull($provider->costPer1kTokens($provider->model(), 'input'));
        $this->assertNotNull($provider->costPer1kTokens($provider->model(), 'output'));
    }

    private function offlineBedrockClient(): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => new MockHandler(),
        ]);
    }
}
