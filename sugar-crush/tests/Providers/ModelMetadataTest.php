<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ModelMetadata;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * Roadmap 5.13a: the LiteLLM model database — lookup, the cache's refresh
 * and offline rules, and the precedence the three consumers apply (operator
 * setting > database > built-in table).
 *
 * NO TEST HERE TOUCHES THE NETWORK. Every refresh runs through
 * {@see ModelMetadata::cachedAt()} with a closure standing in for the
 * download, against a cache path under a per-test temp directory; the
 * production download ({@see ModelMetadata::new()}) is switched off for the
 * whole suite by tests/bootstrap.php, which this file also pins.
 */
final class ModelMetadataTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/model_metadata_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> a small table in LiteLLM's shape */
    private static function litellm(): array
    {
        return [
            'sample_spec' => ['max_input_tokens' => 'max input tokens', 'mode' => 'one of: chat, embedding'],
            'claude-sonnet-4-6' => [
                'max_input_tokens' => 200_000,
                'input_cost_per_token' => 3.0e-6,
                'output_cost_per_token' => 1.5e-5,
                'cache_read_input_token_cost' => 3.0e-7,
                'litellm_provider' => 'anthropic',
                'mode' => 'chat',
            ],
            'gpt-5-made-up' => [
                'max_input_tokens' => 400_000,
                'input_cost_per_token' => 1.25e-6,
                'output_cost_per_token' => 1.0e-5,
                'litellm_provider' => 'openai',
                'mode' => 'chat',
            ],
            'Mixed-Case-Model' => ['max_input_tokens' => 32_768, 'litellm_provider' => 'together_ai', 'mode' => 'chat'],
            'text-embedding-3-large' => ['max_input_tokens' => 8191, 'input_cost_per_token' => 1.3e-7, 'mode' => 'embedding'],
            'junk-row' => ['max_input_tokens' => -5, 'input_cost_per_token' => 'free', 'output_cost_per_token' => 7.0],
            'absurd-window' => ['max_input_tokens' => 10_000_000_000, 'input_cost_per_token' => 1.0e-6],
        ];
    }

    private function cachePath(): string
    {
        return $this->dir . '/model_prices_and_context_window.json';
    }

    // -------------------------------------------------------------------------
    // Lookup
    // -------------------------------------------------------------------------

    public function testAKnownModelAnswersItsWindowAndPer1kRates(): void
    {
        $db = ModelMetadata::fromTable(self::litellm());

        self::assertSame(200_000, $db->contextWindow('claude-sonnet-4-6'));
        self::assertEqualsWithDelta(0.003, $db->costPer1kTokens('claude-sonnet-4-6', 'input'), 1e-12);
        self::assertEqualsWithDelta(0.015, $db->costPer1kTokens('claude-sonnet-4-6', 'output'), 1e-12);
        self::assertEqualsWithDelta(0.0003, $db->costPer1kTokens('claude-sonnet-4-6', 'cached'), 1e-12);
        self::assertNull($db->costPer1kTokens('gpt-5-made-up', 'cached'), 'no cache-read column is no discount');
    }

    public function testMatchingIsExactThenCaseInsensitiveThenProviderPrefixedOnly(): void
    {
        $db = ModelMetadata::fromTable(self::litellm());

        self::assertSame(32_768, $db->contextWindow('mixed-case-model'));
        self::assertSame(200_000, $db->contextWindow('anthropic/claude-sonnet-4-6'), "Aider's provider/id rule");
        self::assertNull($db->contextWindow('openai/claude-sonnet-4-6'), 'the prefix must be the row\'s own provider');
        self::assertNull($db->contextWindow('my-org/claude-sonnet-4-6-local'), 'no fuzzy match: a self-hosted id is not a hosted one');
        self::assertNull($db->contextWindow('Qwen/Qwen3.8-Flash-Next-FP8'));
        self::assertNull($db->contextWindow(''));
    }

    public function testJunkRowsAndNonChatModelsAreDropped(): void
    {
        $db = ModelMetadata::fromTable(self::litellm());

        self::assertNull($db->entry('sample_spec'));
        self::assertNull($db->entry('text-embedding-3-large'), 'an embedding model is not a chat window');
        self::assertNull($db->entry('junk-row'), 'a negative window and non-numeric or absurd rates leave nothing');
        self::assertNull($db->contextWindow('absurd-window'));
        self::assertEqualsWithDelta(0.001, $db->costPer1kTokens('absurd-window', 'input'), 1e-12, 'the sane field of a row survives its junk one');
    }

    public function testADisabledDatabaseKnowsNothing(): void
    {
        self::assertNull(ModelMetadata::disabled()->entry('claude-sonnet-4-6'));
        self::assertNull(ModelMetadata::disabled()->contextWindow('claude-sonnet-4-6'));
    }

    // -------------------------------------------------------------------------
    // The cache and its refresh
    // -------------------------------------------------------------------------

    public function testAFreshCacheIsReadWithoutDownloading(): void
    {
        file_put_contents($this->cachePath(), (string) json_encode(self::litellm()));
        touch($this->cachePath(), self::NOW - 3600);
        $calls = 0;

        $db = ModelMetadata::cachedAt($this->cachePath(), static function () use (&$calls): ?string {
            $calls++;

            return null;
        }, now: self::NOW);

        self::assertSame(400_000, $db->contextWindow('gpt-5-made-up'));
        self::assertSame(0, $calls, 'a cache younger than the 24 h TTL is not refreshed');
    }

    public function testAStaleCacheIsReplacedByACompactedDownload(): void
    {
        file_put_contents($this->cachePath(), (string) json_encode(['old-model' => ['max_input_tokens' => 1000]]));
        touch($this->cachePath(), self::NOW - ModelMetadata::TTL_SECONDS - 1);

        $db = ModelMetadata::cachedAt($this->cachePath(), static fn (): string => (string) json_encode(self::litellm()), now: self::NOW);

        self::assertSame(200_000, $db->contextWindow('claude-sonnet-4-6'));
        self::assertNull($db->entry('old-model'));

        $written = json_decode((string) file_get_contents($this->cachePath()), true);
        self::assertIsArray($written);
        self::assertArrayNotHasKey('sample_spec', $written, 'the cache holds the projected table, not the raw file');
        self::assertArrayNotHasKey('mode', $written['claude-sonnet-4-6']);
        self::assertSame(0o600, fileperms($this->cachePath()) & 0o777);
    }

    public function testAFailedDownloadKeepsTheStaleCacheAndRestartsItsClock(): void
    {
        file_put_contents($this->cachePath(), (string) json_encode(self::litellm()));
        touch($this->cachePath(), self::NOW - ModelMetadata::TTL_SECONDS - 10);

        $db = ModelMetadata::cachedAt($this->cachePath(), static fn (): ?string => null, now: self::NOW);

        self::assertSame(200_000, $db->contextWindow('claude-sonnet-4-6'), 'offline keeps the figures it had');
        clearstatcache();
        self::assertSame(self::NOW, filemtime($this->cachePath()), 'and does not ask again until the TTL runs out');
    }

    public function testAFailedFirstDownloadWritesAnEmptyMarker(): void
    {
        $db = ModelMetadata::cachedAt($this->cachePath(), static fn (): string => 'not json', now: self::NOW);

        self::assertNull($db->contextWindow('claude-sonnet-4-6'));
        self::assertSame('{}', file_get_contents($this->cachePath()));
        clearstatcache();
        self::assertSame(self::NOW, filemtime($this->cachePath()));
    }

    public function testADownloadThatThrowsIsAFailureNotACrash(): void
    {
        $db = ModelMetadata::cachedAt($this->cachePath(), static function (): string {
            throw new \RuntimeException('connection refused');
        }, now: self::NOW);

        self::assertNull($db->contextWindow('claude-sonnet-4-6'));
        self::assertSame('{}', file_get_contents($this->cachePath()));
    }

    public function testARefreshAnotherProcessHoldsIsNotStartedTwice(): void
    {
        file_put_contents($this->cachePath(), (string) json_encode(self::litellm()));
        touch($this->cachePath(), self::NOW - ModelMetadata::TTL_SECONDS - 1);
        $lock = fopen($this->cachePath() . '.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        $calls = 0;

        try {
            $db = ModelMetadata::cachedAt($this->cachePath(), static function () use (&$calls): ?string {
                $calls++;

                return null;
            }, now: self::NOW);

            self::assertSame(200_000, $db->contextWindow('claude-sonnet-4-6'), 'the stale file still answers');
            self::assertSame(0, $calls, 'the lock holder is already refreshing');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The production mode: the refresh runs in a detached grandchild and the
     * caller answers from what it read (nothing, here) without waiting; the
     * file lands shortly after. Without ext-pcntl the same call refreshes
     * inline, so the eventual file is the assertion on both builds.
     */
    public function testABackgroundRefreshReturnsAtOnceAndPublishesTheFileLater(): void
    {
        $body = (string) json_encode(self::litellm());
        $db = ModelMetadata::cachedAt($this->cachePath(), static fn (): string => $body, background: true, now: self::NOW);

        $started = microtime(true);
        $db->contextWindow('claude-sonnet-4-6');
        self::assertLessThan(5.0, microtime(true) - $started);

        $deadline = microtime(true) + 10.0;
        while (!is_file($this->cachePath()) && microtime(true) < $deadline) {
            usleep(20_000);
        }

        self::assertFileExists($this->cachePath());
        // A second spelling of the same file: the parsed table is memoised
        // per cache path for the process, so this reads what the grandchild
        // published rather than the empty table the first read memoised.
        $later = ModelMetadata::cachedAt($this->dir . '/../' . basename($this->dir) . '/' . basename($this->cachePath()), now: self::NOW);
        self::assertSame(200_000, $later->contextWindow('claude-sonnet-4-6'));
    }

    // -------------------------------------------------------------------------
    // The opt-out
    // -------------------------------------------------------------------------

    public function testTheSuiteRunsWithTheDatabaseSwitchedOff(): void
    {
        self::assertTrue(ModelMetadata::disabledFromEnvironment(), 'tests/bootstrap.php must keep the network download off');
        self::assertNull(ModelMetadata::new()->entry('gpt-4o'));
    }

    public function testTheOptOutIsFlagStyle(): void
    {
        $previous = getenv(ModelMetadata::OPT_OUT_ENV);

        try {
            foreach (['' => false, '0' => false, '1' => true, 'yes' => true] as $value => $disabled) {
                putenv(ModelMetadata::OPT_OUT_ENV . '=' . $value);
                self::assertSame($disabled, ModelMetadata::disabledFromEnvironment(), var_export((string) $value, true));
            }
            putenv(ModelMetadata::OPT_OUT_ENV);
            self::assertFalse(ModelMetadata::disabledFromEnvironment(), 'unset');
        } finally {
            putenv($previous === false ? ModelMetadata::OPT_OUT_ENV : ModelMetadata::OPT_OUT_ENV . '=' . $previous);
        }
    }

    // -------------------------------------------------------------------------
    // Consumers: operator setting > database > built-in table
    // -------------------------------------------------------------------------

    private function custom(string $model, ?ModelMetadata $db, array $prices = [], ?int $window = null): CustomProvider
    {
        return new CustomProvider('custom', 'https://api.example.com', $model, null, new Client(), true, true, modelMetadata: $db, modelPrices: $prices, contextWindowOverride: $window);
    }

    public function testTheCustomProviderIsSizedSettingThenDatabaseThen128k(): void
    {
        $db = ModelMetadata::fromTable(self::litellm());

        self::assertSame(200_000, $this->custom('claude-sonnet-4-6', $db)->contextWindow());
        self::assertSame(50_000, $this->custom('claude-sonnet-4-6', $db, window: 50_000)->contextWindow());
        self::assertSame(128_000, $this->custom('Qwen/Qwen3.8-Flash-Next-FP8', $db)->contextWindow());
        self::assertSame(128_000, $this->custom('claude-sonnet-4-6', null)->contextWindow(), 'no database is the pre-5.13a answer');
    }

    public function testTheCustomProviderIsPricedSettingThenDatabaseThenFree(): void
    {
        $db = ModelMetadata::fromTable(self::litellm());

        self::assertEqualsWithDelta(0.003, $this->custom('claude-sonnet-4-6', $db)->costPer1kTokens('claude-sonnet-4-6', 'input'), 1e-12);
        self::assertSame(0.0, $this->custom('local-model', $db)->costPer1kTokens('local-model', 'input'), 'a self-hosted id costs a real $0');

        $declared = $this->custom('claude-sonnet-4-6', $db, ['claude-sonnet-4-6' => ['input' => 1.0, 'output' => 2.0]]);
        self::assertSame(0.001, $declared->costPer1kTokens('claude-sonnet-4-6', 'input'));

        $broken = $this->custom('claude-sonnet-4-6', $db, ['claude-sonnet-4-6' => ['input' => 'cheap', 'output' => 2.0]]);
        self::assertNull($broken->costPer1kTokens('claude-sonnet-4-6', 'input'), 'a named model is never re-priced at the database figure');
    }

    public function testTheCustomProviderBillsAUsageDocumentAtTheResolvedRates(): void
    {
        $db = ModelMetadata::fromTable(self::litellm());
        $usage = ['prompt_tokens' => 10_000, 'completion_tokens' => 1_000, 'total_tokens' => 11_000, 'prompt_tokens_details' => ['cached_tokens' => 8_000]];

        $priced = $this->custom('claude-sonnet-4-6', $db)->parseUsage($usage);
        // 2,000 fresh x 0.003 + 8,000 cached x 0.0003 + 1,000 x 0.015, per 1K.
        self::assertEqualsWithDelta(0.006 + 0.0024 + 0.015, $priced->costUsd, 1e-9);
        self::assertNull($priced->unpricedModel);

        self::assertSame(0.0, $this->custom('local-model', $db)->parseUsage($usage)->costUsd);

        $broken = $this->custom('claude-sonnet-4-6', $db, ['claude-sonnet-4-6' => ['input' => -1, 'output' => 2.0]])->parseUsage($usage);
        self::assertSame(0.0, $broken->costUsd);
        self::assertSame('claude-sonnet-4-6', $broken->unpricedModel, 'a broken declaration is unpriced, loudly');
    }

    public function testTheOpenAiProviderConsultsTheDatabaseBetweenSettingAndTable(): void
    {
        $db = ModelMetadata::fromTable(self::litellm() + ['gpt-4o' => ['max_input_tokens' => 128_000, 'input_cost_per_token' => 2.0e-6, 'output_cost_per_token' => 8.0e-6]]);
        $client = $this->createMock(ClientContract::class);

        self::assertSame(400_000, (new OpenAIProvider($client, 'gpt-5-made-up', modelMetadata: $db))->contextWindow());
        self::assertSame(0, (new OpenAIProvider($client, 'gpt-5-made-up'))->contextWindow(), 'no database: still unknown');
        self::assertSame(1_000, (new OpenAIProvider($client, 'gpt-5-made-up', [], 1_000, null, $db))->contextWindow(), 'the setting wins');

        $openai = new OpenAIProvider($client, 'gpt-4o', modelMetadata: $db);
        self::assertEqualsWithDelta(0.002, $openai->costPer1kTokens('gpt-4o', 'input'), 1e-12, 'the database before the shipped row');
        self::assertEqualsWithDelta(0.00125, $openai->costPer1kTokens('gpt-5-made-up', 'input'), 1e-12, 'and it prices what the table never named');
        self::assertNull($openai->costPer1kTokens('nobody-knows', 'input'));
        self::assertSame(0.004, (new OpenAIProvider($client, 'gpt-4o', ['gpt-4o' => ['input' => 4, 'output' => 8]], null, null, $db))->costPer1kTokens('gpt-4o', 'input'));
    }

    public function testTheFactoryHandsItsDatabaseToCustomAnthropicAndOpenAi(): void
    {
        $factory = new ProviderFactory(ModelMetadata::fromTable(self::litellm()));

        $custom = $factory->create(['type' => 'custom', 'name' => 'gw', 'baseUrl' => 'https://gw.example/v1', 'model' => 'claude-sonnet-4-6']);
        self::assertSame(200_000, $custom->contextWindow());

        $sized = $factory->create(['type' => 'custom', 'name' => 'gw', 'baseUrl' => 'https://gw.example/v1', 'model' => 'claude-sonnet-4-6', 'contextWindow' => 64_000]);
        self::assertSame(64_000, $sized->contextWindow(), 'the provider block outranks the database');

        $anthropic = $factory->create(['type' => 'anthropic', 'apiKey' => 'k', 'model' => 'claude-sonnet-4-6']);
        self::assertSame(200_000, $anthropic->contextWindow());
        self::assertEqualsWithDelta(0.015, $anthropic->costPer1kTokens('claude-sonnet-4-6', 'output'), 1e-12, 'Anthropic bills; it is no longer a fake $0');

        $openai = $factory->create(['type' => 'openai', 'apiKey' => 'k', 'model' => 'gpt-5-made-up']);
        self::assertSame(400_000, $openai->contextWindow());
    }
}
