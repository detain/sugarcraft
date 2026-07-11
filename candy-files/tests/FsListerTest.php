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
}
