<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-073 review fix: `contextWindow` lands on bedrock and sglang.
 *
 * The two TYPE_SCHEMAS rows admitted the key since the ISS-073 commits, but
 * neither provider had the override sink the openai/anthropic/vertex/custom
 * arms carry, so a block that set `contextWindow` was a silent no-op - the
 * exact defect class the issue named, one arm over. Each arm is pinned at
 * both layers the Vertex family pins its own arm: the provider answers the
 * override over the table (with a control leg proving the table answers when
 * no override is armed, so the sink cannot be vacuous), and the factory
 * threads the config key - scalar and map spellings - into the constructor.
 *
 * The sglang override outranks even discovery: the operator who sets the
 * knob means it (the same authority rule `$modelPrices` follows), which the
 * deepseek control leg makes discriminating - without the override the
 * family row answers 1,048,570, so a passing 250,000 can only come from the
 * sink.
 */
final class BedrockSglangContextWindowTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandboxHome = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandboxHome = sys_get_temp_dir() . '/bsx_ctx_home_' . uniqid('', true);
        $this->useHomeSandbox($this->sandboxHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->sandboxHome);

        parent::tearDown();
    }

    public function testBedrockTheFamilyTableStillAnswersWithoutAnOverride(): void
    {
        $provider = new BedrockProvider($this->bedrockClient(), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $this->assertSame(1_000_000, $provider->contextWindow(), 'control leg: the table answers when no override is armed');
    }

    public function testBedrockTheOverrideOutranksTheTable(): void
    {
        $provider = new BedrockProvider(
            $this->bedrockClient(),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
            contextWindowOverride: 400_000,
        );

        $this->assertSame(400_000, $provider->contextWindow());
    }

    public function testBedrockTheFactoryFeedsTheBlockKeyIntoTheOverride(): void
    {
        $factory = new ProviderFactory();

        $provider = $factory->create([
            'type' => 'bedrock',
            'region' => 'us-east-1',
            'model' => 'anthropic.claude-sonnet-4-6',
            'contextWindow' => 512_000,
        ]);

        $this->assertInstanceOf(BedrockProvider::class, $provider);
        $this->assertSame(512_000, $provider->contextWindow());
    }

    public function testBedrockTheFactoryAcceptsThePerModelMapSpelling(): void
    {
        $factory = new ProviderFactory();

        // Map keyed by another model: THIS model is unnamed in it, so the
        // table still answers - the normaliser must not fire on a miss.
        $unmatched = $factory->create([
            'type' => 'bedrock',
            'region' => 'us-east-1',
            'model' => 'anthropic.claude-sonnet-4-6',
            'contextWindow' => ['gpt-4o' => 512_000],
        ]);
        $this->assertSame(1_000_000, $unmatched->contextWindow());

        $named = $factory->create([
            'type' => 'bedrock',
            'region' => 'us-east-1',
            'model' => 'anthropic.claude-sonnet-4-6',
            'contextWindow' => ['anthropic.claude-sonnet-4-6' => 333_000],
        ]);
        $this->assertSame(333_000, $named->contextWindow());
    }

    public function testBedrockWithModelCarriesTheOverrideAcrossTheRebind(): void
    {
        // The rebind clones via get_object_vars(); the sink must ride it, or
        // a /model switch silently demotes the operator's number to the table.
        $provider = new BedrockProvider(
            $this->bedrockClient(),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
            contextWindowOverride: 400_000,
        );

        $this->assertSame(400_000, $provider->withModel('anthropic.claude-haiku-4-5')->contextWindow());
    }

    public function testSglangTheFamilyRowStillAnswersWithoutAnOverride(): void
    {
        $provider = SglangProvider::openAiCompatible('https://sglang.invalid', 'deepseek-v4-pro');

        $this->assertSame(1_048_570, $provider->contextWindow(), 'control leg: the deepseek row answers when no override is armed');
    }

    public function testSglangTheOverrideOutranksTheDerivedRows(): void
    {
        $provider = SglangProvider::openAiCompatible(
            'https://sglang.invalid',
            'deepseek-v4-pro',
            contextWindowOverride: 250_000,
        );

        $this->assertSame(250_000, $provider->contextWindow());
    }

    public function testSglangTheFactoryFeedsTheBlockKeyIntoTheOverride(): void
    {
        $factory = new ProviderFactory();

        $provider = $factory->create([
            'type' => 'sglang',
            'baseUrl' => 'https://sglang.invalid/v1',
            'model' => 'deepseek-v4-pro',
            'contextWindow' => 128_000,
        ]);

        $this->assertInstanceOf(SglangProvider::class, $provider);
        $this->assertSame(128_000, $provider->contextWindow());
    }

    public function testSglangTheFactoryAcceptsThePerModelMapSpelling(): void
    {
        $factory = new ProviderFactory();

        $unmatched = $factory->create([
            'type' => 'sglang',
            'baseUrl' => 'https://sglang.invalid/v1',
            'model' => 'deepseek-v4-pro',
            'contextWindow' => ['qwen3.8-flash-next' => 512_000],
        ]);
        $this->assertSame(1_048_570, $unmatched->contextWindow());

        $named = $factory->create([
            'type' => 'sglang',
            'baseUrl' => 'https://sglang.invalid/v1',
            'model' => 'deepseek-v4-pro',
            'contextWindow' => ['deepseek-v4-pro' => 777_000],
        ]);
        $this->assertSame(777_000, $named->contextWindow());
    }

    private function bedrockClient(): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => new MockHandler(),
        ]);
    }
}
