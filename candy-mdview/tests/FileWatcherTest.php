<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\MdView\FileWatcher;

/**
 * @covers \SugarCraft\MdView\FileWatcher
 */
final class FileWatcherTest extends TestCase
{
    public function testHasChangedSinceReturnsFalseForNonExistentFile(): void
    {
        $watcher = new FileWatcher('/non/existent/file.txt');

        self::assertFalse($watcher->hasChangedSince(0));
    }

    public function testHasChangedSinceReturnsFalseWhenNotModified(): void
    {
        $path = sys_get_temp_dir() . '/test_watcher_' . uniqid() . '.txt';
        file_put_contents($path, 'content');

        $mtime = filemtime($path);
        $watcher = new FileWatcher($path);

        self::assertFalse($watcher->hasChangedSince($mtime));

        unlink($path);
    }

    public function testHasChangedSinceDetectsOlderMtime(): void
    {
        $path = sys_get_temp_dir() . '/test_watcher_older_' . uniqid() . '.txt';
        file_put_contents($path, 'initial content');
        $baselineMtime = filemtime($path);
        // Touch the file to an OLDER (smaller) mtime than the baseline.
        touch($path, $baselineMtime - 100);
        $watcher = new FileWatcher($path);
        // hasChangedSince returns true because current mtime !== baseline mtime
        // (even though the file is older, not newer).
        self::assertTrue($watcher->hasChangedSince($baselineMtime));
        unlink($path);
    }

    public function testHasChangedSinceReturnsTrueWhenModified(): void
    {
        $path = sys_get_temp_dir() . '/test_watcher_' . uniqid() . '.txt';
        file_put_contents($path, 'initial content');

        $mtime = filemtime($path);
        $watcher = new FileWatcher($path);

        // Deterministic instead of wall-clock: the edit lands first, then an
        // explicit future mtime GUARANTEES hasChangedSince() sees a value
        // distinct from the baseline on any filesystem granularity (the same
        // future-touch idiom MdViewModelReloadTest uses; touching AFTER the
        // write matters — file_put_contents would rewind a pre-write touch
        // back to "now", inside the very second that caused the race). The
        // old sleep(1) burned ~45% of the suite's runtime anyway.
        file_put_contents($path, 'modified content');
        touch($path, (int) $mtime + 7);
        clearstatcache(true, $path);

        self::assertTrue($watcher->hasChangedSince($mtime));

        unlink($path);
    }

    public function testHasChangedSinceReturnsFalseWhenFileDeleted(): void
    {
        $path = sys_get_temp_dir() . '/test_watcher_deleted_' . uniqid() . '.txt';
        file_put_contents($path, 'content');
        $mtime = filemtime($path);

        $watcher = new FileWatcher($path);
        unlink($path);

        // File no longer exists, should return false
        self::assertFalse($watcher->hasChangedSince($mtime));
    }
    // testConstructorStoresPath removed: its assertion was byte-identical to
    // testHasChangedSinceReturnsFalseForNonExistentFile above (a false
    // answer on a never-existing path proves nothing about stored state).
}
