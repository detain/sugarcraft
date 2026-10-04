<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\EmbeddingCache;

/**
 * Roadmap 5.3-2: the embedding vector cache keyed by (model, exact text).
 */
final class EmbeddingCacheTest extends TestCase
{
    private string $dir;

    /** @var list<list<string>> every batch the fake embedder was asked for */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/embcache_' . bin2hex(random_bytes(4));
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function testMissesAreEmbeddedOnceAndHitsAreServedFromTheCache(): void
    {
        $cache = EmbeddingCache::at($this->dir . '/e.sqlite');

        $first = $cache->vectors('m', ['alpha', 'beta', 'alpha'], $this->embedder());
        $second = $cache->vectors('m', ['beta', 'alpha', 'gamma'], $this->embedder());

        self::assertSame([['alpha', 'beta'], ['gamma']], $this->calls, 'a duplicate and a cached text are never re-embedded');
        self::assertSame($first[0], $first[2]);
        self::assertSame($first[1], $second[0]);
        self::assertSame($first[0], $second[1]);
        self::assertSame(3, $cache->count());
    }

    public function testVectorsSurviveANewInstanceOnTheSameFile(): void
    {
        EmbeddingCache::at($this->dir . '/e.sqlite')->vectors('m', ['alpha'], $this->embedder());
        $this->calls = [];

        $vectors = EmbeddingCache::at($this->dir . '/e.sqlite')->vectors('m', ['alpha'], $this->embedder());

        self::assertSame([], $this->calls);
        self::assertEqualsWithDelta([5.0, 1.0], $vectors[0], 1e-6);
    }

    public function testAnotherModelNeverSharesAVector(): void
    {
        $cache = EmbeddingCache::at($this->dir . '/e.sqlite');
        $cache->vectors('m1', ['alpha'], $this->embedder());
        $cache->vectors('m2', ['alpha'], $this->embedder());

        self::assertSame([['alpha'], ['alpha']], $this->calls);
    }

    public function testMissesAreBatched(): void
    {
        $texts = array_map(static fn (int $i): string => "t{$i}", range(1, EmbeddingCache::BATCH_SIZE + 3));

        EmbeddingCache::inMemory()->vectors('m', $texts, $this->embedder());

        self::assertSame([EmbeddingCache::BATCH_SIZE, 3], array_map('count', $this->calls));
    }

    public function testTheInMemoryCacheWorksWithoutAFile(): void
    {
        $cache = EmbeddingCache::inMemory();
        $cache->vectors('m', ['alpha'], $this->embedder());
        $cache->vectors('m', ['alpha'], $this->embedder());

        self::assertNull($cache->file());
        self::assertCount(1, $this->calls);
        self::assertSame(1, $cache->count());
    }

    public function testAWrongNumberOfVectorsThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('answered 1 vectors for 2 inputs');

        EmbeddingCache::inMemory()->vectors('m', ['a', 'b'], static fn (array $texts): array => [[1.0]]);
    }

    public function testANonNumericVectorThrowsAndIsNotStored(): void
    {
        $cache = EmbeddingCache::inMemory();
        $threw = false;
        try {
            $cache->vectors('m', ['a'], static fn (array $texts): array => [['x']]);
        } catch (\RuntimeException) {
            $threw = true;
        }

        self::assertTrue($threw, 'a non-numeric vector must not be accepted');
        self::assertSame(0, $cache->count());
    }

    public function testADamagedFileIsRebuilt(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/e.sqlite', str_repeat('not a database ', 100));
        $cache = EmbeddingCache::at($this->dir . '/e.sqlite');

        $cache->vectors('m', ['alpha'], $this->embedder());
        $cache->vectors('m', ['alpha'], $this->embedder());

        self::assertCount(1, $this->calls, 'the second call is served by the rebuilt file');
    }

    public function testTheFileIsOwnerOnlyAndLivesUnderTheHomeCache(): void
    {
        $cache = EmbeddingCache::at($this->dir . '/e.sqlite');
        $cache->vectors('m', ['alpha'], $this->embedder());

        self::assertSame(0600, fileperms($this->dir . '/e.sqlite') & 0777);
        self::assertSame('/home/x/.sugar-crush/cache/embeddings.sqlite', EmbeddingCache::defaultPath('/home/x/'));
    }

    /** @return \Closure(list<string>): list<list<float>> */
    private function embedder(): \Closure
    {
        return function (array $texts): array {
            $this->calls[] = $texts;

            return array_map(static fn (string $text): array => [(float) \strlen($text), 1.0], $texts);
        };
    }
}
