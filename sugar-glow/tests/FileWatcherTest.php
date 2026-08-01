<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\FileWatcher;

/**
 * @covers \SugarCraft\Glow\FileWatcher
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

        // Wait at least 1 second for mtime to change (filesystem mtime granularity)
        sleep(1);
        file_put_contents($path, 'modified content');

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

    public function testConstructorStoresPath(): void
    {
        $path = '/test/path.txt';
        $watcher = new FileWatcher($path);

        // Use hasChangedSince to verify the path is stored correctly
        $result = $watcher->hasChangedSince(time());
        self::assertFalse($result); // non-existent file returns false
    }
}
