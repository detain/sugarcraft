<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\RepoMap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\RepoMap\PhpSymbolExtractor;
use SugarCraft\Crush\RepoMap\Tag;
use SugarCraft\Crush\RepoMap\TagCache;

final class TagCacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/tag_cache_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/proj/src', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        if (\is_dir($path) && !\is_link($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            \rmdir($path);
        } elseif (\file_exists($path) || \is_link($path)) {
            \unlink($path);
        }
    }

    public function testGetMissesUntilPutThenHitsOnlyForTheSameMtimeAndSize(): void
    {
        $cache = TagCache::open($this->dir . '/c/cache.sqlite');
        $tags = [
            Tag::definition('src/A.php', 3, 'A', Tag::TYPE_CLASS),
            Tag::definition('src/A.php', 4, 'run', Tag::TYPE_METHOD, 'A'),
            Tag::reference('src/A.php', 5, 'Helper'),
        ];

        self::assertNull($cache->get('src/A.php', 100, 20));
        $cache->put('src/A.php', 100, 20, $tags);

        self::assertEquals($tags, $cache->get('src/A.php', 100, 20));
        self::assertNull($cache->get('src/A.php', 101, 20), 'a newer mtime is a miss');
        self::assertNull($cache->get('src/A.php', 100, 21), 'a same-second rewrite of another size is a miss');
        self::assertSame(['files' => 1, 'tags' => 3], $cache->stats());
    }

    public function testPutReplacesAFilesPreviousTags(): void
    {
        $cache = TagCache::open($this->dir . '/cache.sqlite');
        $cache->put('a.php', 1, 1, [Tag::reference('a.php', 1, 'Old'), Tag::reference('a.php', 2, 'Older')]);
        $cache->put('a.php', 2, 2, [Tag::reference('a.php', 1, 'New')]);

        self::assertSame(['New'], \array_map(static fn (Tag $t) => $t->name, $cache->get('a.php', 2, 2) ?? []));
        self::assertSame(['files' => 1, 'tags' => 1], $cache->stats());
    }

    public function testTagsForExtractsOnceAndReExtractsAfterTheFileChanges(): void
    {
        $file = $this->dir . '/proj/src/A.php';
        \file_put_contents($file, "<?php\nclass A {}\n");
        \touch($file, 1_000_000);
        $cache = TagCache::open($this->dir . '/cache.sqlite');
        $extractor = PhpSymbolExtractor::new();

        self::assertSame('A', $cache->tagsFor($file, 'src/A.php', $extractor)[0]->name);

        // Same mtime and size, different bytes: the cache answers, which
        // proves the second call did not tokenize.
        \file_put_contents($file, "<?php\nclass B {}\n");
        \touch($file, 1_000_000);
        self::assertSame('A', $cache->tagsFor($file, 'src/A.php', $extractor)[0]->name);

        \touch($file, 1_000_001);
        self::assertSame('B', $cache->tagsFor($file, 'src/A.php', $extractor)[0]->name);
    }

    public function testTagsForADeletedFileForgetsIt(): void
    {
        $file = $this->dir . '/proj/src/Gone.php';
        \file_put_contents($file, "<?php\nclass Gone {}\n");
        $cache = TagCache::open($this->dir . '/cache.sqlite');
        $cache->tagsFor($file, 'src/Gone.php', PhpSymbolExtractor::new());
        \unlink($file);

        self::assertSame([], $cache->tagsFor($file, 'src/Gone.php', PhpSymbolExtractor::new()));
        self::assertSame(['files' => 0, 'tags' => 0], $cache->stats());
    }

    public function testForgetAndPruneDropStaleEntries(): void
    {
        $cache = TagCache::open($this->dir . '/cache.sqlite');
        foreach (['a.php', 'b.php', 'c.php'] as $path) {
            $cache->put($path, 1, 1, [Tag::reference($path, 1, 'X')]);
        }

        $cache->forget('a.php');
        self::assertNull($cache->get('a.php', 1, 1));

        self::assertSame(1, $cache->prune(['b.php']));
        self::assertSame(0, $cache->prune(['b.php']));
        self::assertNotNull($cache->get('b.php', 1, 1));
        self::assertSame(['files' => 1, 'tags' => 1], $cache->stats());
    }

    public function testEntriesSurviveReopeningTheDatabase(): void
    {
        $path = $this->dir . '/cache.sqlite';
        TagCache::open($path)->put('a.php', 7, 8, [Tag::reference('a.php', 1, 'Kept')]);

        $reopened = TagCache::open($path);
        self::assertSame($path, $reopened->path());
        self::assertSame('Kept', ($reopened->get('a.php', 7, 8) ?? [])[0]->name);
    }

    public function testAnExtractorVersionChangeWipesEveryRow(): void
    {
        $path = $this->dir . '/cache.sqlite';
        TagCache::open($path)->put('a.php', 1, 1, [Tag::reference('a.php', 1, 'Stale')]);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("UPDATE meta SET value = '0' WHERE key = 'extractor_version'");
        unset($pdo);

        self::assertSame(['files' => 0, 'tags' => 0], TagCache::open($path)->stats());
    }

    public function testACorruptDatabaseIsRebuiltRatherThanFatal(): void
    {
        $path = $this->dir . '/cache.sqlite';
        \file_put_contents($path, \str_repeat('not a database ', 200));

        $cache = TagCache::open($path);
        $cache->put('a.php', 1, 1, [Tag::reference('a.php', 1, 'Fresh')]);

        self::assertSame(['files' => 1, 'tags' => 1], $cache->stats());
    }

    public function testTheDatabaseIsOwnerOnlyAndItsDirectoryIsCreated(): void
    {
        $path = $this->dir . '/deep/er/cache.sqlite';
        TagCache::open($path);

        self::assertFileExists($path);
        self::assertSame(0600, \fileperms($path) & 0777);
        self::assertSame(0700, \fileperms(\dirname($path)) & 0777);
    }

    public function testOpenThrowsWhenTheDirectoryCannotBeCreated(): void
    {
        \file_put_contents($this->dir . '/blocker', 'a file, not a directory');

        $this->expectException(\RuntimeException::class);
        TagCache::open($this->dir . '/blocker/cache.sqlite');
    }

    public function testDefaultPathLivesUnderHomeKeyedByTheProjectKey(): void
    {
        $root = $this->dir . '/proj';
        $expected = '/home/u/.sugar-crush/cache/repomap/' . MemoryStore::projectKeyFor($root) . '.sqlite';

        self::assertSame($expected, TagCache::defaultPath($root, '/home/u/'));
        self::assertStringStartsNotWith($root, (string) TagCache::defaultPath($root, '/home/u'));
    }

    public function testDefaultPathIsNullWithoutAHome(): void
    {
        self::assertNull(TagCache::defaultPath($this->dir, ''));
    }
}
