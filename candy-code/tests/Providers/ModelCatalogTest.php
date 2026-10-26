<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\HasModelCatalog;
use SugarCraft\Code\Providers\ModelCatalog;
use SugarCraft\Code\Providers\ModelMetadata;

/**
 * PRV-1 (§6.5): the once-per-process catalog memo and the merge order it
 * feeds the palette and the `models all` listing.
 */
final class ModelCatalogTest extends TestCase
{
    private string|false $originalOptOut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalOptOut = getenv(ModelMetadata::OPT_OUT_ENV);
        ModelCatalog::resetForTesting();
    }

    protected function tearDown(): void
    {
        $this->restoreOptOut();
        ModelCatalog::resetForTesting();
        parent::tearDown();
    }

    private static function counting(string $key, ?array $answer): HasModelCatalog
    {
        return new class($key, $answer) implements HasModelCatalog {
            public int $calls = 0;

            /** @param list<string>|null $answer */
            public function __construct(private string $key, private ?array $answer)
            {
            }

            public function catalogKey(): ?string
            {
                return $this->key;
            }

            public function listModels(): ?array
            {
                ++$this->calls;

                return $this->answer;
            }
        };
    }

    public function testPrefetchAsksEachKeyOnceAndRemembersEvenTheFailures(): void
    {
        $this->allowDiscovery();

        $good = self::counting('https://a/v1', ['x', 'y']);
        ModelCatalog::prefetch($good);
        ModelCatalog::prefetch($good);
        self::assertSame(['x', 'y'], ModelCatalog::live($good));
        self::assertSame(1, $good->calls, 'the second open reads the memo, not the endpoint');

        $dead = self::counting('https://dead.invalid/v1', null);
        ModelCatalog::prefetch($dead);
        ModelCatalog::prefetch($dead);
        self::assertNull(ModelCatalog::live($dead));
        self::assertSame(1, $dead->calls, 'an unreachable endpoint is asked about once per process');
    }

    public function testLiveAndPrefetchIgnoreAProviderWithoutAKey(): void
    {
        $this->allowDiscovery();

        $keyless = self::counting('k', null);
        $void = new class implements HasModelCatalog {
            public int $calls = 0;

            public function catalogKey(): ?string
            {
                return null;
            }

            public function listModels(): ?array
            {
                ++$this->calls;

                return ['never'];
            }
        };

        ModelCatalog::prefetch($void);
        self::assertSame(0, $void->calls, 'no key means nothing to memoise against, so nothing is asked');
        self::assertNull(ModelCatalog::live($void));
        self::assertNull(ModelCatalog::live($keyless), 'peeking an unfetched key answers null');
    }

    /**
     * The whole model-discovery family — cached table AND live listing —
     * answers to one opt-out. The suite sets it globally; this is the pin
     * that says why a green suite never touched an endpoint.
     */
    public function testTheDiscoveryOptOutHoldsTheLiveTierBack(): void
    {
        putenv(ModelMetadata::OPT_OUT_ENV . '=1');
        self::assertTrue(ModelMetadata::disabledFromEnvironment());

        $held = self::counting('https://opt-out.invalid/v1', ['x']);
        ModelCatalog::prefetch($held);
        self::assertSame(0, $held->calls, 'opted out means no request, not a memoised failure');
        self::assertNull(ModelCatalog::live($held));

        $this->allowDiscovery();
        ModelCatalog::prefetch($held);
        self::assertSame(1, $held->calls, 'clearing the flag lets the same provider be asked');
        self::assertSame(['x'], ModelCatalog::live($held));
    }

    /** `0` is the flag-style "not set" form; the suite default (1) is not. */
    private function allowDiscovery(): void
    {
        putenv(ModelMetadata::OPT_OUT_ENV . '=0');
    }

    private function restoreOptOut(): void
    {
        if ($this->originalOptOut === false) {
            putenv(ModelMetadata::OPT_OUT_ENV);
        } else {
            putenv(ModelMetadata::OPT_OUT_ENV . '=' . $this->originalOptOut);
        }
    }

    public function testLitellmFamiliesAreMappedAndSelfHostedNamesHaveNone(): void
    {
        $table = ModelMetadata::fromTable([
            'claude-sonnet-4-6' => ['max_input_tokens' => 200_000, 'litellm_provider' => 'anthropic'],
            'gpt-5' => ['max_input_tokens' => 400_000, 'litellm_provider' => 'openai'],
            'bedrock/anthropic.claude-sonnet-4-5-v2:0' => ['max_input_tokens' => 200_000, 'litellm_provider' => 'bedrock'],
            'vertex_ai/gemini-2.5-pro' => ['max_input_tokens' => 1_000_000, 'litellm_provider' => 'vertex_ai'],
            'glm-4.7' => ['max_input_tokens' => 200_000, 'litellm_provider' => 'z.ai'],
        ]);

        self::assertSame(['claude-sonnet-4-6'], ModelCatalog::litellm('anthropic', $table));
        self::assertSame(['claude-sonnet-4-6'], ModelCatalog::litellm('Anthropic', $table), 'the family lookup folds case like entry()');
        self::assertSame(['gemini-2.5-pro'], ModelCatalog::litellm('vertex', $table), 'PRV-1 MAJOR-1: the vertex_ai/ routing prefix is LiteLLM\'s namespace — the palette row is the native id Vertex accepts');
        self::assertSame(['anthropic.claude-sonnet-4-5-v2:0'], ModelCatalog::litellm('bedrock', $table), 'PRV-1 MAJOR-1: bedrock/ strips, the :0 version suffix is native spelling and rides');
        self::assertSame([], ModelCatalog::litellm('sglang', $table), 'a self-hosted transport invents no hosted family');
        self::assertSame([], ModelCatalog::litellm('custom', $table));
        self::assertSame([], ModelCatalog::litellm('echo', $table));
    }

    /**
     * fx/palette-sec review MINOR-1: the LiteLLM table is remote download
     * bytes, and a JSON `\u001b`-escaped control byte is WELL-FORMED (only
     * the raw byte is refused by the parse — the same escape form the
     * endpoint tier's pins use). A poisoned database could otherwise ship
     * an OSC-8 id straight into the palette rows and `models all` via the
     * same raw paint path. The table twin of the ingest sweep lives in
     * {@see ModelMetadata::candidates()}; an id that sweeps to nothing
     * falls, and honest ids round-trip byte-identical.
     */
    public function testTheLitellmTierSweepsEscapedControlBytesFromTableKeys(): void
    {
        $hostile = json_decode('{"openai/\\u001b]8;;https://evil\\u0007hostile-table-row":{"litellm_provider":"openai","max_input_tokens":1000},"gpt-4o\\r\\n":{"litellm_provider":"openai","max_input_tokens":1000},"\\u001b[31m":{"litellm_provider":"openai","max_input_tokens":1000}}', true);
        self::assertIsArray($hostile, 'the escaped form is valid JSON — json_decode delivers live control bytes');

        $rows = ModelCatalog::litellm('openai', ModelMetadata::fromTable($hostile));

        self::assertSame(['gpt-4o', 'hostile-table-row'], $rows, 'the OSC-8 payload sweeps to its visible label, the trailing-CRLF key trims to the honest id, the pure-escape row falls; sorted order');
        foreach ($rows as $row) {
            self::assertSame(0, preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $row), 'no litellm-tier row reaches merge() with a control byte');
        }
    }

    /**
     * PRV-1 (MAJOR-1): the palette's merged rows are what Chat persists and
     * the transport wires — with a family-filtered LiteLLM tier they are all
     * native endpoint ids, deduped against the bare and configured rows.
     */
    public function testTheMergedPaletteCarriesNativeLabelsNotRoutingAliases(): void
    {
        $table = ModelMetadata::fromTable([
            'gpt-4o' => ['max_input_tokens' => 128_000, 'litellm_provider' => 'openai'],
            'openai/gpt-4o' => ['max_input_tokens' => 128_000, 'litellm_provider' => 'openai'],
            'openai/gpt-4.1-mini' => ['max_input_tokens' => 1_000_000, 'litellm_provider' => 'openai'],
            'openrouter/meta-llama/llama-3.1-8b-instruct' => ['max_input_tokens' => 131_072, 'litellm_provider' => 'openai'],
        ]);

        $rows = ModelCatalog::merge(['gpt-4o'], ['gpt-4.1-mini'], ModelCatalog::litellm('openai', $table));

        self::assertSame(['gpt-4o', 'gpt-4.1-mini'], $rows, 'the configured row leads, the stripped alias dedupes against the bare and the live tier, and the openrouter-foreign row is excluded');
        foreach ($rows as $row) {
            self::assertStringNotContainsString('/gpt', $row, 'no row persists as a LiteLLM routing alias');
        }
    }

    public function testMergeKeepsTiersInOrderDeduplicatedAndCapped(): void
    {
        self::assertSame(
            ['now', 'also', 'live-1', 'live-2', 'lit-1'],
            ModelCatalog::merge(['now', 'also', 'live-1'], ['live-1', 'live-2'], ['lit-1', 'now']),
        );

        self::assertSame(
            ['now', 'live-1', 'lit-1'],
            ModelCatalog::merge(['now'], ['live-1'], ['lit-1', 'lit-2'], limit: 3),
            'the cap truncates the last tier that fits, oldest-first across tiers',
        );

        self::assertSame(['now'], ModelCatalog::merge(['now'], null, []), 'a cold or failed live tier is the absence of a tier');
        self::assertSame([], ModelCatalog::merge([], [], []));
    }
}
