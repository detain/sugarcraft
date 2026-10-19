<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\HasModelCatalog;
use SugarCraft\Code\Providers\ModelCatalog;
use SugarCraft\Code\Providers\ModelMetadata;

/**
 * PRV-1 (§6.5): the once-per-process catalog memo and the merge order it
 * feeds the palette and `models --all`.
 */
final class ModelCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelCatalog::resetForTesting();
    }

    protected function tearDown(): void
    {
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
        self::assertSame(['vertex_ai/gemini-2.5-pro'], ModelCatalog::litellm('vertex', $table));
        self::assertSame([], ModelCatalog::litellm('sglang', $table), 'a self-hosted transport invents no hosted family');
        self::assertSame([], ModelCatalog::litellm('custom', $table));
        self::assertSame([], ModelCatalog::litellm('echo', $table));
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
