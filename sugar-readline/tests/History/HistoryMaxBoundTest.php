<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Readline\History\FileHistory;
use SugarCraft\Readline\History\InMemoryHistory;

/**
 * S-30 (findings 012, 032, 065, 101): one live cap per store — settable in
 * place, applied at once, fail-fast on negatives, honored on disk.
 *
 * The mutating setMaxHistory() is the canonical arm; withMaxHistory()
 * survives only as a deprecated BC forwarder that mutates THIS store and
 * returns it, so the dropped-return call site that the old clone silently
 * defeated now delivers the effect (the pattern is impossible to miswrite).
 * Navigation is stateful, so a trim below the cursor is pinned through the
 * walk, and FileHistory's shared-file law is pinned with fresh readers on
 * the raw bytes.
 */
final class HistoryMaxBoundTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (@\glob($dir . '/*') ?: [] as $f) {
                @\unlink((string) $f);
            }
            @\rmdir($dir);
        }
        $this->dirs = [];
    }

    /**
     * @return array{0: string, 1: string} path + dir
     */
    private function scopedDir(): array
    {
        $dir = \sys_get_temp_dir() . '/rl-cap-' . bin2hex(random_bytes(4));
        if (!\mkdir($dir, 0700)) {
            $this->fail('cannot create temp dir');
        }
        $this->dirs[] = $dir;
        return [$dir . '/history.txt', $dir];
    }

    public function testInMemoryCapTrimsAtSetTimeNewestSurvive(): void
    {
        $history = new InMemoryHistory(0);
        foreach (['a', 'b', 'c', 'd'] as $entry) {
            $history->push($entry);
        }
        // Newest-first storage: d is the most recent push.
        self::assertSame(['d', 'c', 'b', 'a'], $history->entries());

        $returned = $history->setMaxHistory(2);

        self::assertSame($history, $returned, 'the mutating arm returns $this');
        self::assertSame(['d', 'c'], $history->entries());
        self::assertSame(2, $history->count());
    }

    public function testZeroMeansUnlimitedInBothArms(): void
    {
        $history = new InMemoryHistory();          // default cap 0
        for ($i = 0; $i < 130; $i++) {
            $history->push("e{$i}");
        }
        self::assertSame(130, $history->count());

        $history->setMaxHistory(50);
        self::assertSame(50, $history->count());

        $history->setMaxHistory(0);                // back to unlimited...
        $history->push('extra');
        for ($i = 0; $i < 60; $i++) {
            $history->push("f{$i}");
        }
        self::assertSame(111, $history->count());  // 50 + 61, nothing evicted
    }

    public function testTrimMidWalkReclampsTheCursor(): void
    {
        $history = new InMemoryHistory(0);
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $entry) {
            $history->push($entry);                // newest-first: echo..alpha
        }
        self::assertSame('echo', $history->previous());      // pos 0
        self::assertSame('delta', $history->previous());     // pos 1
        self::assertSame('charlie', $history->previous());   // pos 2

        $history->setMaxHistory(2);                // purges charlie's slot

        // The walk must continue from the newest survivor, never strand on
        // a purged index (finding 101).
        self::assertSame('echo', $history->next());          // back toward the buffer: pos 1 -> 0
        self::assertSame('delta', $history->previous());     // pos 1 = the trimmed tail
        self::assertNull($history->previous(), 'exhausted at the trimmed tail, no ghost slot');
        self::assertSame('echo', $history->next());
        self::assertNull($history->next(), 'live buffer reached');
    }

    public function testTrimToEmptyParksTheCursorAtTheLiveBuffer(): void
    {
        $history = new InMemoryHistory(0);
        $history->push('only');
        self::assertSame('only', $history->previous());

        $history->setMaxHistory(0);                // no-op: unlimited
        self::assertSame('only', $history->entries()[0]);

        // No cap trims, so the purge path is clear(): cursor law parity.
        $history->clear();
        self::assertNull($history->previous());
        self::assertNull($history->next());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function negativeCaps(): array
    {
        return [[-1], [-2], [-100], [\PHP_INT_MIN]];
    }

    #[DataProvider('negativeCaps')]
    public function testNegativeCapsThrowEverywhere(int $cap): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InMemoryHistory(0))->setMaxHistory($cap);
    }

    public function testNegativeConstructorSeedsThrowInBothStores(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InMemoryHistory(-1);
    }

    public function testNegativeConstructorSeedThrowsForFileHistory(): void
    {
        [$path, $dir] = $this->scopedDir();
        try {
            new FileHistory($path, -1, $dir);
            $this->fail('a negative file cap must not seed silently as unlimited');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must not be negative', $e->getMessage());
        }
        // Nothing was created by the refused open.
        self::assertFileDoesNotExist($path);
    }

    public function testDeprecatedForwarderMutatesThisStoreAndAnnounces(): void
    {
        $history = new InMemoryHistory(0);
        foreach (['a', 'b', 'c'] as $entry) {
            $history->push($entry);
        }

        $seen = [];
        \set_error_handler(
            static function (int $errno, string $errstr) use (&$seen): bool {
                if ($errno === E_USER_DEPRECATED) {
                    $seen[] = $errstr;
                }
                return true;
            }
        );
        try {
            $returned = $history->withMaxHistory(2);
        } finally {
            \restore_error_handler();
        }

        self::assertSame($history, $returned, 'the forwarder hands back the SAME store');
        self::assertSame(['c', 'b'], $history->entries(), 'the effect lands where the caller expects it');
        self::assertSame(
            ['SugarCraft\Readline\History\InMemoryHistory::withMaxHistory() is deprecated; use setMaxHistory()'],
            $seen
        );
    }

    public function testDeprecatedMarkersRideTheForwarderDocblock(): void
    {
        $doc = (string) (new ReflectionMethod(InMemoryHistory::class, 'withMaxHistory'))->getDocComment();
        self::assertStringContainsString('@deprecated', $doc);
        self::assertStringContainsString('REMOVED-IN: 2.0.0', $doc);
    }

    public function testDeadGetLastEntryIsGone(): void
    {
        // Finding: the protected accessor had zero callers tree-wide; the
        // house census rule deletes rather than deprecates dead members.
        self::assertFalse(method_exists(InMemoryHistory::class, 'getLastEntry'));
        self::assertFalse(method_exists(FileHistory::class, 'getLastEntry'));
    }

    public function testFileHistoryShrinkRewritesTheSharedDisk(): void
    {
        [$path, $dir] = $this->scopedDir();
        // Seed an over-cap file behind the store's back (a foreign writer).
        \file_put_contents($path, "one\ntwo\nthree\nfour\nfive\n");

        $history = new FileHistory($path, 0, $dir);   // unlimited load: 5 entries
        self::assertSame(['five', 'four', 'three', 'two', 'one'], $history->entries());

        $history->setMaxHistory(2);

        self::assertSame(['five', 'four'], $history->entries());
        // The disk file itself honors the bound — a FRESH process reading it
        // must not resurrect the discarded entries (finding 065).
        self::assertSame("#sugar-readline-history/v2\nfour\nfive\n", (string) \file_get_contents($path));
        $fresh = new FileHistory($path, 100, $dir);
        self::assertSame(['five', 'four'], $fresh->entries());
    }

    public function testFileHistoryUnlimitedToCapBoundsExistingFile(): void
    {
        [$path, $dir] = $this->scopedDir();
        $history = new FileHistory($path, 0, $dir);
        foreach (['a', 'b', 'c', 'd', 'e'] as $entry) {
            $history->push($entry);
        }

        $history->setMaxHistory(3);

        self::assertSame(['e', 'd', 'c'], $history->entries());
        $fresh = new FileHistory($path, 0, $dir);
        self::assertSame(['e', 'd', 'c'], $fresh->entries());
    }

    public function testFileHistoryGrowAndEqualSetNeverTouchTheFile(): void
    {
        [$path, $dir] = $this->scopedDir();
        $history = new FileHistory($path, 5, $dir);
        $history->push('a');
        $history->push('b');
        clearstatcache();
        $bytes = (string) \file_get_contents($path);
        $mtime = (int) \filemtime($path);

        $history->setMaxHistory(5);   // equal cap
        $history->setMaxHistory(10);  // grow

        self::assertSame($bytes, (string) \file_get_contents($path), 'no-op cap sets must not rewrite');
        self::assertSame($mtime, (int) \filemtime($path));

        // The grown cap accepts more without rewriting history.
        $history->push('c');
        self::assertSame(['c', 'b', 'a'], $history->entries());
    }

    public function testFileHistoryDeferredShrinkDrainsThePendingQueue(): void
    {
        [$path, $dir] = $this->scopedDir();
        $history = new FileHistory($path, 100, $dir, true);
        $history->push('a');
        $history->push('b');
        $history->push('c');
        self::assertSame('', (string) \file_get_contents($path), 'deferred mode has written no entries yet');

        $history->setMaxHistory(2);

        self::assertSame(['c', 'b'], $history->entries());
        self::assertSame("#sugar-readline-history/v2\nb\nc\n", (string) \file_get_contents($path));
        $history->flush(); // no-op now: the queue drained into the set-time commit
        self::assertSame("#sugar-readline-history/v2\nb\nc\n", (string) \file_get_contents($path));
    }

    public function testFileHistoryFailedBoundCommitLeavesEverythingUnmoved(): void
    {
        [$path, $dir] = $this->scopedDir();
        $history = new FileHistory($path, 0, $dir);
        $history->push('first');
        $history->push('second');
        // Poison the sidecar: the bounding rewrite must refuse.
        @\unlink($path . '.lock');
        if (!\symlink($dir . '/victim', $path . '.lock')) {
            $this->fail('unable to plant the lock symlink for the failure seam');
        }
        clearstatcache();

        try {
            $history->setMaxHistory(1);
            $this->fail('expected the poisoned lock to refuse the bounding rewrite');
        } catch (\RuntimeException $e) {
            // Persist-then-swap: neither memory nor the cap scalar moved.
            self::assertSame(['second', 'first'], $history->entries());
            $capProp = new \ReflectionProperty(InMemoryHistory::class, 'maxHistory');
            $capProp->setAccessible(true);
            self::assertSame(0, $capProp->getValue($history), 'the cap rolled back to its prior value');
        } finally {
            @\unlink($path . '.lock');
            clearstatcache();
        }
    }
}
