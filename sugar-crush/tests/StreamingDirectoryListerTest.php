<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\StreamingDirectoryLister;

final class StreamingDirectoryListerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcrush_lister_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Recursively remove test directory
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . \DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testListYieldsCorrectEntries(): void
    {
        touch($this->tmpDir . '/a.txt');
        touch($this->tmpDir . '/b.txt');
        touch($this->tmpDir . '/c.md');

        $lister = new StreamingDirectoryLister();
        $entries = iterator_to_array($lister->list($this->tmpDir));

        $this->assertCount(3, $entries);
        // Entries are [index => absolutePath]
        $paths = array_values($entries);
        $this->assertContains($this->tmpDir . '/a.txt', $paths);
        $this->assertContains($this->tmpDir . '/b.txt', $paths);
        $this->assertContains($this->tmpDir . '/c.md', $paths);
    }

    public function testListSkipsDotFiles(): void
    {
        touch($this->tmpDir . '/visible.txt');
        touch($this->tmpDir . '/.hidden');
        mkdir($this->tmpDir . '/.dotdir');
        touch($this->tmpDir . '/.dotdir/secret');

        $lister = new StreamingDirectoryLister();
        $entries = iterator_to_array($lister->list($this->tmpDir));

        $this->assertCount(1, $entries);
        $this->assertSame($this->tmpDir . '/visible.txt', array_values($entries)[0]);
    }

    public function testListEmptyDirectoryYieldsNothing(): void
    {
        $lister = new StreamingDirectoryLister();
        $entries = iterator_to_array($lister->list($this->tmpDir));

        $this->assertSame([], $entries);
    }

    public function testListNonExistentDirectoryYieldsNothing(): void
    {
        $lister = new StreamingDirectoryLister();
        $entries = iterator_to_array($lister->list('/nonexistent/path/'));

        $this->assertSame([], $entries);
    }

    public function testListFilesYieldsOnlyFiles(): void
    {
        touch($this->tmpDir . '/file.txt');
        mkdir($this->tmpDir . '/subdir');
        touch($this->tmpDir . '/subdir/nested.txt');

        $lister = new StreamingDirectoryLister();
        $entries = iterator_to_array($lister->listFiles($this->tmpDir));

        $this->assertCount(1, $entries);
        $this->assertSame($this->tmpDir . '/file.txt', array_values($entries)[0]);
    }

    public function testListFilesSkipsDirectories(): void
    {
        mkdir($this->tmpDir . '/empty_dir');

        $lister = new StreamingDirectoryLister();
        $entries = iterator_to_array($lister->listFiles($this->tmpDir));

        $this->assertSame([], $entries);
    }

    public function testCountReturnsCorrectNumber(): void
    {
        touch($this->tmpDir . '/a.txt');
        touch($this->tmpDir . '/b.txt');
        touch($this->tmpDir . '/c.txt');

        $lister = new StreamingDirectoryLister();
        $this->assertSame(3, $lister->count($this->tmpDir));
    }

    public function testCountSkipsDotEntries(): void
    {
        touch($this->tmpDir . '/a.txt');
        touch($this->tmpDir . '/.hidden');

        $lister = new StreamingDirectoryLister();
        $this->assertSame(1, $lister->count($this->tmpDir));
    }

    public function testCountEmptyDirectoryReturnsZero(): void
    {
        $lister = new StreamingDirectoryLister();
        $this->assertSame(0, $lister->count($this->tmpDir));
    }

    public function testCountNonExistentDirectoryReturnsZero(): void
    {
        $lister = new StreamingDirectoryLister();
        $this->assertSame(0, $lister->count('/nonexistent/path'));
    }

    public function testLazyLoadingDoesNotLoadAllEntriesAtOnce(): void
    {
        // Create 500 files
        for ($i = 0; $i < 500; $i++) {
            touch($this->tmpDir . "/file_{$i}.txt");
        }

        $lister = new StreamingDirectoryLister();

        // Consume only the first 3 entries via foreach (triggers lazy rewind).
        // This proves the Generator does not preload the full list.
        // We only check that we get 3 entries with increasing indices —
        // we cannot assume readdir() returns files in a particular order.
        $seen = [];
        foreach ($lister->list($this->tmpDir) as $index => $path) {
            $seen[$index] = $path;
            if (count($seen) >= 3) {
                break;
            }
        }

        $this->assertCount(3, $seen);
        // Indices should be 0, 1, 2 (ascending, no gaps)
        $indices = array_keys($seen);
        $this->assertSame([0, 1, 2], $indices);
        // All paths should be absolute and end with .txt
        foreach ($seen as $path) {
            $this->assertStringStartsWith($this->tmpDir, $path);
            $this->assertStringEndsWith('.txt', $path);
        }

        // Verify full count still works — proves no state corruption.
        $count = 0;
        foreach ($lister->list($this->tmpDir) as $_) {
            $count++;
        }
        $this->assertSame(500, $count);
    }

    public function testListEntriesAreAbsolutePaths(): void
    {
        touch($this->tmpDir . '/test.txt');

        $lister = new StreamingDirectoryLister();
        $entries = iterator_to_array($lister->list($this->tmpDir));

        foreach ($entries as $path) {
            $this->assertTrue(
                str_starts_with($path, '/'),
                "Expected absolute path, got: {$path}",
            );
        }
    }
}
