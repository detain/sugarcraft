<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemorySearchIndex;
use SugarCraft\Crush\Memory\MemoryStore;

/**
 * Roadmap 5.3-1: `MemoryStore::search()` ranks through an FTS5 BM25 index that
 * is a derived cache of the note files — rebuilt from their mtimes, invisible
 * to every listing, absent from a repository's tree, and replaced by the old
 * substring scan when FTS5 is not there.
 */
final class MemorySearchIndexTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/msi_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dir);
    }

    public function testResultsAreRankedByBm25(): void
    {
        $store = new MemoryStore($this->dir);
        $weak = $store->add('Deploys run from CI; staging is mentioned once in a long note about many other unrelated things like caching and logging', 'user');
        $strong = $store->add('Staging deploy: run bin/deploy staging, then smoke-test staging', 'user');
        $store->add('Python packaging uses uv', 'user');

        $ids = array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('deploy staging'));

        self::assertSame([$strong, $weak], $ids);
    }

    public function testEveryWordMustMatchAndWordsMatchAsStemmedPrefixes(): void
    {
        $store = new MemoryStore($this->dir);
        $both = $store->add('We are deploying the API to staging', 'user');
        $store->add('The deploy script lives in bin/', 'user');

        $ids = array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('deploy staging'));

        self::assertSame([$both], $ids);
    }

    public function testTagsAndTypeAreSearchedAndATagHitOutranksProse(): void
    {
        $store = new MemoryStore($this->dir);
        $prose = $store->add('The release checklist lives in docs/', 'user');
        $tagged = $store->add('Bump the version first', 'user', ['release']);

        $ids = array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('release'));
        self::assertSame([$tagged, $prose], $ids);

        self::assertCount(2, $store->search('pattern'), 'the type column is searched too');
    }

    public function testASubstringOnlyMatchStillFollowsTheRankedHits(): void
    {
        $store = new MemoryStore($this->dir);
        $word = $store->add('oyster farming notes', 'user');
        $inner = $store->add('the deploy-staging pipeline', 'user');

        $ids = array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('oy'));

        // `oy` is a word prefix of `oyster` (ranked) and a substring of
        // `deploy` (the scan's old hit, kept after it).
        self::assertSame([$word, $inner], $ids);
    }

    public function testQuerySyntaxIsMatchedAsWordsNeverInterpreted(): void
    {
        $store = new MemoryStore($this->dir);
        $id = $store->add('use NEAR OR AND as literal words', 'user');

        self::assertSame([$id], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('NEAR "OR" AND')));
        self::assertSame([], $store->search('content:literal -words'));
        self::assertSame('"a"* "b"*', MemorySearchIndex::matchExpression('a b a'));
        self::assertNull(MemorySearchIndex::matchExpression('-- ::'));
    }

    public function testAHandEditedNoteIsReindexedByItsMtime(): void
    {
        $store = new MemoryStore($this->dir);
        $id = $store->add('Redis runs on port 6379', 'user');
        self::assertCount(1, $store->search('redis'));

        $file = $this->dir . '/user/' . $id . '.md';
        file_put_contents($file, str_replace('Redis runs on port 6379', 'Valkey replaced the cache', (string) file_get_contents($file)));
        touch($file, time() + 5);

        self::assertSame([], $store->search('redis'));
        self::assertCount(1, $store->search('valkey'));

        // A fresh store over the same directory reads the same cache file.
        self::assertCount(1, (new MemoryStore($this->dir))->search('valkey'));
    }

    public function testAWriteInsideTheIndexedSecondIsStillSeen(): void
    {
        $store = new MemoryStore($this->dir);
        $id = $store->add('alpha', 'user');
        self::assertCount(1, $store->search('alpha'));

        // Same size, same second, written behind the store's back: only the
        // "mtime not older than the indexing second" rule can catch it.
        $file = $this->dir . '/user/' . $id . '.md';
        $mtime = filemtime($file);
        file_put_contents($file, str_replace('alpha', 'gamma', (string) file_get_contents($file)));
        touch($file, (int) $mtime);

        self::assertCount(1, (new MemoryStore($this->dir))->search('gamma'));
    }

    public function testDeletedAndClearedNotesLeaveTheIndex(): void
    {
        $store = new MemoryStore($this->dir);
        $a = $store->add('kafka topic naming', 'user');
        $store->add('kafka consumer groups', 'project');
        self::assertCount(2, $store->search('kafka'));

        $store->delete($a);
        self::assertCount(1, $store->search('kafka'));

        $store->clear('project');
        self::assertSame([], $store->search('kafka'));

        // Removed behind the store's back.
        $b = $store->add('kafka retention', 'user');
        self::assertCount(1, $store->search('kafka'));
        unlink($this->dir . '/user/' . $b . '.md');
        self::assertSame([], $store->search('kafka'));
    }

    public function testUnreadableNotesAreReportedEvenWhenUnchanged(): void
    {
        $store = new MemoryStore($this->dir);
        $store->add('fine note', 'user');
        file_put_contents($this->dir . '/user/broken.md', "no frontmatter at all\n");

        $store->search('note');
        self::assertArrayHasKey($this->dir . '/user/broken.md', $store->skipped());

        // Second search, new store: the file is unchanged and not re-read, yet
        // its reason still reaches skipped().
        $fresh = new MemoryStore($this->dir);
        $fresh->search('note');
        self::assertArrayHasKey($this->dir . '/user/broken.md', $fresh->skipped());
    }

    public function testTheCacheIsAnOwnerOnlyDotFileNoListingReads(): void
    {
        $store = new MemoryStore($this->dir);
        $store->add('a note', 'user');
        $store->search('note');

        $cache = $this->dir . '/' . MemorySearchIndex::FILENAME;
        self::assertFileExists($cache);
        self::assertSame(0600, fileperms($cache) & 0777);
        self::assertCount(1, $store->list('user'));
        self::assertSame([], $store->unreadable());
    }

    public function testAProjectKeyedStoreUsesItsOwnCacheFile(): void
    {
        $store = MemoryStore::forProject($this->dir, '/srv/app');
        $store->add('keyed note', 'project');
        $store->search('keyed');

        self::assertFileExists($this->dir . '/.search-' . $store->projectKey() . '.sqlite');
        self::assertFileDoesNotExist($this->dir . '/' . MemorySearchIndex::FILENAME);
    }

    public function testARepositoryStoreWritesNoCacheIntoTheTree(): void
    {
        $store = MemoryStore::forRepository($this->dir);
        $id = $store->add('repo note about webpack', 'project');

        self::assertSame([$id], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('webpack')));
        // Wildcard split so the literal is not harvested into the PathGlob corpus.
        self::assertSame([], glob($this->dir . '/.search' . '*') ?: []);
    }

    public function testWithoutFts5TheSubstringScanAnswers(): void
    {
        $store = new MemoryStore($this->dir);
        $index = MemorySearchIndex::at($this->dir . '/.nofts.sqlite', 'fts5_not_a_module');
        $store->useSearchIndex($index);
        $a = $store->add('the deploy-staging pipeline', 'user');
        $store->add('unrelated', 'user');

        self::assertSame([$a], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('PLOY-STA')));
        self::assertTrue($index->unavailable());
    }

    public function testADamagedCacheFallsBackOnceAndIsRebuilt(): void
    {
        $store = new MemoryStore($this->dir);
        $id = $store->add('survives a broken cache', 'user');
        $cache = $this->dir . '/' . MemorySearchIndex::FILENAME;
        file_put_contents($cache, str_repeat('not a database ', 100));

        self::assertSame([$id], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('broken')));
        self::assertFileDoesNotExist($cache, 'the damaged file is discarded');

        self::assertSame([$id], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('broken')));
        self::assertFileExists($cache, 'and rebuilt by the next search');
    }

    public function testASwitchedOffIndexCreatesNoFile(): void
    {
        $store = new MemoryStore($this->dir);
        $store->useSearchIndex(null);
        $id = $store->add('plain scan', 'user');

        self::assertSame([$id], array_map(static fn(MemoryEntry $e): string => $e->id(), $store->search('scan')));
        self::assertFileDoesNotExist($this->dir . '/' . MemorySearchIndex::FILENAME);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
