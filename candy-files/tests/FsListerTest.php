<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\Entry;
use SugarCraft\Files\FsLister;
use SugarCraft\Files\Pane;

final class FsListerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy_files_lister_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            // is_link check first: never recurse through a symlinked dir.
            if (is_link($full) || !is_dir($full)) {
                @unlink($full);
            } else {
                $this->removeDir($full);
            }
        }
        @rmdir($path);
    }

    private function entryNamed(array $entries, string $name): ?Entry
    {
        foreach ($entries as $e) {
            if ($e->name === $name) {
                return $e;
            }
        }
        return null;
    }

    /**
     * A symlink whose target is a directory must report isDir=true (so it is
     * navigable) while keeping isLink=true. lstat() alone reports the link's
     * own mode (S_IFLNK) → isDir=false, which is the bug this guards. Revert
     * the FsLister fix and isDir is false → this fails.
     */
    public function testSymlinkedDirectoryReportsIsDirTrue(): void
    {
        $realDir = $this->tmpDir . '/realdir';
        mkdir($realDir, 0755, true);
        file_put_contents($realDir . '/inside.txt', 'x');
        if (@symlink($realDir, $this->tmpDir . '/linkdir') === false) {
            $this->markTestSkipped('symlinks not supported on this filesystem');
        }

        $entries = (FsLister::lister())($this->tmpDir);
        $link = $this->entryNamed($entries, 'linkdir');

        $this->assertNotNull($link, 'linkdir must be listed');
        $this->assertTrue($link->isDir, 'symlinked directory must report isDir=true');
        $this->assertTrue($link->isLink, 'symlink flag must be preserved');
    }

    /**
     * Because a symlinked directory now reports isDir=true, navigate() enters
     * it. Before the fix isDir was false and navigate() no-oped on it. Revert
     * the FsLister fix and cwd stays put → this fails.
     */
    public function testSymlinkedDirectoryIsNavigable(): void
    {
        $realDir = $this->tmpDir . '/realdir';
        mkdir($realDir, 0755, true);
        file_put_contents($realDir . '/inside.txt', 'x');
        $linkPath = $this->tmpDir . '/linkdir';
        if (@symlink($realDir, $linkPath) === false) {
            $this->markTestSkipped('symlinks not supported on this filesystem');
        }

        $lister = FsLister::lister();
        $pane = Pane::open($this->tmpDir, $lister);

        $names = array_map(static fn(Entry $e) => $e->name, $pane->entries);
        $idx = array_search('linkdir', $names, true);
        $this->assertNotFalse($idx, 'linkdir must appear in the pane');

        $next = $pane->moveCursor((int) $idx)->navigate($lister);

        $this->assertSame($linkPath, $next->cwd, 'navigating into a symlinked dir must enter it');
    }

    /**
     * CF-22 (closes audit 031): the lister contract splits "empty" from
     * "cannot read". A missing path is no longer a lying [] — it answers
     * null so the UI can tell the two facts apart.
     */
    public function testMissingPathAnswersNull(): void
    {
        $this->assertNull((FsLister::lister())($this->tmpDir . '/never-existed-candy-files'));
    }

    public function testPlainFileAnswersNull(): void
    {
        file_put_contents($this->tmpDir . '/f.txt', 'x');
        $this->assertNull((FsLister::lister())($this->tmpDir . '/f.txt'), 'a file is not a listing — answer null, not empty');
    }

    public function testEmptyReadableDirectoryAnswersEmptyListNotNull(): void
    {
        mkdir($this->tmpDir . '/vacant', 0755, true);
        $entries = (FsLister::lister())($this->tmpDir . '/vacant');
        $this->assertIsArray($entries, 'an empty dir is a FACT, not a refusal');
        $this->assertSame([], $entries);
    }

    public function testUnreadableDirectoryAnswersNull(): void
    {
        $secret = $this->tmpDir . '/secret';
        mkdir($secret, 0755, true);
        file_put_contents($secret . '/topsecret.txt', 'x');
        chmod($secret, 0000);
        if (is_readable($secret)) {
            chmod($secret, 0755);
            $this->markTestSkipped('this environment defeats directory read permissions (running as root?)');
        }

        try {
            $this->assertNull((FsLister::lister())($secret), 'a 0500 dir answers null, never a lying []');
        } finally {
            chmod($secret, 0755);
        }
    }

    /** CF-39: same mtime = one scan; an mtime bump (entry-set move) re-reads. */
    public function testMemoizeServesRepeatReadsWithoutTouchingTheInnerLister(): void
    {
        $calls = 0;
        $inner = function (string $path) use (&$calls): ?array {
            $calls++;
            return [new Entry('a', false, 1, 0)];
        };
        $mtimes = ['/x' => 100];
        $memo = FsLister::memoize($inner, static function (string $path) use (&$mtimes): ?int {
            return $mtimes[$path] ?? null;
        });
        $first = $memo('/x');
        $second = $memo('/x');
        $this->assertSame(1, $calls, 'same mtime = one scan');
        $this->assertSame($first, $second);
        $mtimes['/x'] = 101;
        $memo('/x');
        $this->assertSame(2, $calls, 'mtime bump invalidates the memo');
    }

    /** CF-39: nulls stored, nulls distrusted over a live stat, absence memoized. */
    public function testMemoizeStoresAndInvalidatesNulls(): void
    {
        $calls = 0;
        $inner = function () use (&$calls): ?array {
            $calls++;
            return null;
        };
        $mtimes = ['/w' => 50];
        $memo = FsLister::memoize($inner, static function (string $path) use (&$mtimes): ?int {
            return $mtimes[$path] ?? null;
        });
        $this->assertNull($memo('/w'));
        $this->assertNull($memo('/w'));
        $this->assertSame(2, $calls, 'a refusal over a live stat re-lists: r recovers a restored permission');
        $mtimes['/w'] = null;
        $this->assertNull($memo('/w'));
        $this->assertNull($memo('/w'));
        $this->assertSame(3, $calls, 'an unstatable dir memoizes its absence');
        $mtimes['/w'] = 77;
        $this->assertNull($memo('/w'));
        $this->assertSame(4, $calls, 'null-mtime entries self-invalidate when stat returns');
    }

    /** CF-39: the invalidator bypasses an mtime tie — the same-second op case. */
    public function testMemoInvalidatorDropsASlotDespiteAnUnchangedMtime(): void
    {
        $calls = 0;
        $inner = function () use (&$calls): ?array {
            $calls++;
            return [new Entry('n' . $calls, false, 1, 0)];
        };
        [$memo, $forget] = FsLister::memo($inner, static fn(string $p): ?int => 7);
        $this->assertSame(['n1'], array_map(static fn(Entry $e): string => $e->name, $memo('/s')));
        $this->assertSame(['n1'], array_map(static fn(Entry $e): string => $e->name, $memo('/s')));
        $this->assertSame(1, $calls, 'unchanged mtime is a cache hit');
        $forget('/s');
        $this->assertSame(['n2'], array_map(static fn(Entry $e): string => $e->name, $memo('/s')));
        $this->assertSame(2, $calls, 'after a forget the same mtime must NOT answer from the memo');
    }
}
