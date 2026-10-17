<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\VertexProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-073: Vertex answered every contextWindow() as a flat 200,000, so a
 * claude-sonnet-4-6 (1M window) or gemini-2.5 (1,048,576) compaction profile
 * was sized for a fifth of the room, and the user-tier `contextWindow`
 * setting - honoured on openai, anthropic and custom - was not even an
 * accepted key of the vertex block. Vertex now carries a per-family table
 * (Claude rows mirroring BedrockProvider, Gemini rows at the published
 * 1,048,576) with the same override normaliser the other arms use.
 */
final class VertexContextWindowTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandboxHome = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandboxHome = sys_get_temp_dir() . '/vertex_ctx_home_' . uniqid('', true);
        $this->useHomeSandbox($this->sandboxHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->sandboxHome);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function modelIds(): iterable
    {
        yield 'the ISS-025 default is a 1M family' => ['claude-sonnet-4-6', 1_000_000];
        yield 'dated sonnet-4-6 alias' => ['claude-sonnet-4-6@20250929', 1_000_000];
        yield 'old sonnet stays 200K' => ['claude-sonnet-4@20250514', 200_000];
        yield 'retired claude 3 sonnet stays 200K' => ['claude-3-sonnet@20240229', 200_000];
        yield 'haiku is 200K' => ['claude-haiku-4-5', 200_000];
        yield 'opus-4-6 joins the million row' => ['claude-opus-4-6', 1_000_000];
        yield 'gemini 2.5 pro takes the full million-minus-a-bit' => ['gemini-2.5-pro', 1_048_576];
        yield 'gemini 2.0 flash too' => ['gemini-2.0-flash', 1_048_576];
        yield 'unknown family falls to the status-quo floor' => ['gemini-3-pro-maybe', 200_000];
        yield 'unknown claude falls to the floor' => ['claude-nextnext-9', 200_000];
    }

    #[DataProvider('modelIds')]
    public function testTheFamilyTableSizesTheWindow(string $model, int $expected): void
    {
        $provider = VertexProvider::create(projectId: 'proj-ctx', model: $model);

        $this->assertSame($expected, $provider->contextWindow());
    }

    public function testTheOverrideOutranksTheTable(): void
    {
        $provider = VertexProvider::create(
            projectId: 'proj-ctx',
            model: 'claude-sonnet-4-6',
            contextWindowOverride: 400_000,
        );

        $this->assertSame(400_000, $provider->contextWindow());
    }

    public function testTheFactoryFeedsTheBlockKeyIntoTheOverride(): void
    {
        $factory = new ProviderFactory();

        $provider = $factory->create([
            'type' => 'vertex',
            'projectId' => 'proj-ctx',
            'model' => 'gemini-2.5-pro',
            'contextWindow' => 512_000,
        ]);

        $this->assertInstanceOf(VertexProvider::class, $provider);
        $this->assertSame(512_000, $provider->contextWindow());
    }

    public function testTheFactoryAcceptsThePerModelMapSpelling(): void
    {
        $factory = new ProviderFactory();

        $provider = $factory->create([
            'type' => 'vertex',
            'projectId' => 'proj-ctx',
            'model' => 'claude-sonnet-4-6',
            // Map keyed by other models: THIS model is unnamed, so it rides
            // the table, not somebody else's override.
            'contextWindow' => ['gpt-4o' => 512_000],
        ]);

        $this->assertInstanceOf(VertexProvider::class, $provider);
        $this->assertSame(1_000_000, $provider->contextWindow());

        $named = $factory->create([
            'type' => 'vertex',
            'projectId' => 'proj-ctx',
            'model' => 'claude-sonnet-4-6',
            'contextWindow' => ['claude-sonnet-4-6' => 128_000],
        ]);

        $this->assertSame(128_000, $named->contextWindow());
    }

    public function testNonsenseOverrideValuesFallBackToTheTable(): void
    {
        $factory = new ProviderFactory();

        // Zero, negatives and non-numbers are no setting (same tolerance as
        // the other arms documented in SETTINGS.md).
        foreach ([0, -5, 'plenty'] as $nonsense) {
            $provider = $factory->create([
                'type' => 'vertex',
                'projectId' => 'proj-ctx',
                'model' => 'claude-sonnet-4-6',
                'contextWindow' => $nonsense,
            ]);

            $this->assertSame(1_000_000, $provider->contextWindow(), var_export($nonsense, true));
        }
    }

    public function testTheSchemaAdmitsContextWindowOnTheThreeArms(): void
    {
        // ISS-073 asks for the key to be accepted on vertex, bedrock and
        // sglang blocks. The declarative list is the contract here
        // (validateRequiredKeys only enforces `required`); the vertex arm is
        // additionally wired end-to-end by the tests above, while bedrock
        // and sglang still size from their own built-in tables until their
        // providers take the override sink.
        $reflection = new \ReflectionClass(ProviderFactory::class);
        $schema = $reflection->getConstant('TYPE_SCHEMAS');

        $this->assertIsArray($schema);
        foreach (['vertex', 'bedrock', 'sglang'] as $type) {
            $this->assertContains(
                'contextWindow',
                $schema[$type]['optional'],
                $type . ' block must accept contextWindow',
            );
        }
    }
}
