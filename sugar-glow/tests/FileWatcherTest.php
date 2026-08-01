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

    // --- FileWatcher::watch() tests ---

    public function testWatchReturnsGeneratorForExistingFile(): void
    {
        $path = sys_get_temp_dir() . '/test_watcher_gen_' . uniqid() . '.txt';
        file_put_contents($path, 'content');
        try {
            $gen = FileWatcher::watch($path, 100);
            $this->assertInstanceOf(\Generator::class, $gen);
            // Advance once to enter the loop and capture initial state
            $gen->rewind();
            // Initial state captured, no change detected yet
            $this->assertNull($gen->current());
            // Valid because generator hasn't closed
            $this->assertTrue($gen->valid());
        } finally {
            unlink($path);
        }
    }

    public function testWatchReturnsGeneratorForNonExistentFile(): void
    {
        $gen = FileWatcher::watch('/non/existent/file.txt');
        // Returns an empty generator (early return)
        $this->assertInstanceOf(\Generator::class, $gen);
        $this->assertNull($gen->current());
        $this->assertFalse($gen->valid());
    }

    public function testWatchReturnsGeneratorWhenFilemtimeFails(): void
    {
        // On Windows, chmod doesn't work as expected; use a device file path
        // that filemtime will fail on
        $gen = FileWatcher::watch('/dev/null/should-not-exist');
        $this->assertInstanceOf(\Generator::class, $gen);
        $this->assertNull($gen->current());
    }
}
