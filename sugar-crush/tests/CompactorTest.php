<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Compactor;
use SugarCraft\Crush\CompactedGroup;

final class CompactorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcrush_compactor_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
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

    private function makeFile(string $name, int $sizeBytes = 10): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, str_repeat('x', $sizeBytes));
        return $path;
    }

    public function testEmptyArrayReturnsEmptyList(): void
    {
        $compactor = new Compactor(thresholdBytes: 1024);
        $this->assertSame([], $compactor->compact([]));
    }

    public function testLargeFilesAreNotCompacted(): void
    {
        $small = $this->makeFile('small.txt', 100);           // < 1 KB
        $large = $this->makeFile('large.txt', 2048);         // >= 1 KB

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$small, $large]);

        // Large file must be its own group (not compact)
        $largeGroups = array_filter($groups, static fn(CompactedGroup $g) => !$g->isCompact);
        $this->assertCount(1, $largeGroups);

        // Small file must be in a compact group
        $compactGroups = array_filter($groups, static fn(CompactedGroup $g) => $g->isCompact);
        $this->assertCount(1, $compactGroups);
    }

    public function testSmallFilesAreGroupedByCategory(): void
    {
        $php1 = $this->makeFile('a.php', 100);
        $php2 = $this->makeFile('b.php', 100);
        $png1 = $this->makeFile('c.png', 100);

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$php1, $php2, $png1]);

        // Two groups: code (php files) and images (png)
        $this->assertCount(2, $groups);

        $codeGroups = array_filter(
            $groups,
            static fn(CompactedGroup $g) => $g->label === 'code',
        );
        $this->assertCount(1, $codeGroups);

        $imageGroups = array_filter(
            $groups,
            static fn(CompactedGroup $g) => $g->label === 'images',
        );
        $this->assertCount(1, $imageGroups);
    }

    public function testFilesAtThresholdAreNotCompacted(): void
    {
        // Exactly threshold is NOT small — edge case
        $atThreshold = $this->makeFile('exact.bin', 1024);
        $below       = $this->makeFile('small.bin', 100);

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$atThreshold, $below]);

        $atThresholdGroup = array_filter(
            $groups,
            static fn(CompactedGroup $g) => !$g->isCompact && $g->paths[0] === $atThreshold,
        );
        $this->assertCount(1, $atThresholdGroup);
    }

    public function testCompactedGroupIsCompactFlagIsTrue(): void
    {
        $small = $this->makeFile('tiny.txt', 50);

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$small]);

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->isCompact);
        $this->assertSame('other', $groups[0]->label);
    }

    public function testLargeFileGroupIsNotCompact(): void
    {
        $large = $this->makeFile('big.bin', 2048);

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$large]);

        $this->assertCount(1, $groups);
        $this->assertFalse($groups[0]->isCompact);
        $this->assertSame($large, $groups[0]->label);
    }

    public function testCategoryForReturnsCorrectCategory(): void
    {
        $compactor = new Compactor();

        $this->assertSame('images',  $compactor->categoryFor('/path/to/photo.png'));
        $this->assertSame('images',  $compactor->categoryFor('/path/to/photo.JPG'));
        $this->assertSame('docs',    $compactor->categoryFor('/path/to/doc.pdf'));
        $this->assertSame('code',   $compactor->categoryFor('/path/to/script.php'));
        $this->assertSame('code',   $compactor->categoryFor('/path/to/script.PHP'));
        $this->assertSame('video',  $compactor->categoryFor('/path/to/movie.mp4'));
        $this->assertSame('audio',  $compactor->categoryFor('/path/to/song.mp3'));
        $this->assertSame('archives', $compactor->categoryFor('/path/to/archive.zip'));
        $this->assertSame('data',  $compactor->categoryFor('/path/to/table.csv'));
        $this->assertSame('config', $compactor->categoryFor('/path/to/app.conf'));
        $this->assertSame('other',  $compactor->categoryFor('/path/to/weird.xyz'));
        $this->assertSame('other',  $compactor->categoryFor('/path/to/noextension'));
    }

    public function testThresholdAccessors(): void
    {
        $compactor = new Compactor(thresholdBytes: 512);
        $this->assertSame(512, $compactor->threshold());

        $default = new Compactor();
        $this->assertSame(1024, $default->threshold());
    }

    public function testMaxPerGroupSplitsLargeBuckets(): void
    {
        // Create 120 small PHP files (over 3 groups of maxPerGroup=50)
        $paths = [];
        for ($i = 0; $i < 120; $i++) {
            $paths[] = $this->makeFile("file_{$i}.php", 50);
        }

        $compactor = new Compactor(thresholdBytes: 1024, maxPerGroup: 50);
        $groups = $compactor->compact($paths);

        // All should be compact groups
        $compactGroups = array_filter($groups, static fn(CompactedGroup $g) => $g->isCompact);
        $this->assertCount(3, $compactGroups);
    }

    public function testCompactedGroupCount(): void
    {
        $p1 = $this->makeFile('a.txt', 50);
        $p2 = $this->makeFile('b.txt', 50);

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$p1, $p2]);

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]->count());
    }

    public function testCompactedGroupTotalSize(): void
    {
        $p1 = $this->makeFile('a.bin', 100);
        $p2 = $this->makeFile('b.bin', 200);

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$p1, $p2]);

        $this->assertCount(1, $groups);
        $this->assertSame(300, $groups[0]->totalSize());
    }

    public function testNonExistentFileNotCompacted(): void
    {
        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact(['/nonexistent/file.txt']);

        // Non-existent files are treated as large (is_file returns false → not small)
        $this->assertCount(1, $groups);
        $this->assertFalse($groups[0]->isCompact);
    }

    public function testMixOfCategoriesPreserved(): void
    {
        $php = $this->makeFile('a.php', 100);
        $png = $this->makeFile('b.png', 100);
        $csv = $this->makeFile('c.csv', 100);

        $compactor = new Compactor(thresholdBytes: 1024);
        $groups = $compactor->compact([$php, $png, $csv]);

        $labels = array_map(static fn(CompactedGroup $g) => $g->label, $groups);
        $this->assertContains('code',   $labels);
        $this->assertContains('images', $labels);
        $this->assertContains('data',  $labels);
    }
}
