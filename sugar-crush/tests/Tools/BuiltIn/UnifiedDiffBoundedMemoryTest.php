<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Write;

/**
 * Edit and Write cost memory in proportion to the file, not ~18x it (audit
 * F-T2).
 *
 * The diff used to split both whole files into line arrays and build one op
 * per unchanged line, so a one-line Edit of a 36 MB file peaked at 650 MB; a
 * Write overwrite read the previous contents in full and paid the same again.
 * Each test here measures the peak growth across a single `execute()` and
 * holds it under 3x the file size. The arguments (and, for Write, the new
 * content) are already in memory before the measurement starts, so the
 * growth is what the tool itself allocates.
 *
 * @see \SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff
 */
final class UnifiedDiffBoundedMemoryTest extends TestCase
{
    private const FILE_BYTES = 20 * 1024 * 1024;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/diff_bounded_memory_' . uniqid('', true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testEditOfALargeFileStaysUnderThreeTimesItsSize(): void
    {
        $size = $this->writeLargeFile('big.txt');
        $edit = new Edit($this->root, maxBytes: 32 * 1024 * 1024);
        $args = ['id' => 'c', 'file_path' => 'big.txt', 'old_string' => 'UNIQUE_MARKER', 'new_string' => 'CHANGED'];

        [$result, $growth] = $this->measure(static fn () => $edit->execute($args));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('(+1 -1 lines)', $result->content());
        $this->assertStringContainsString("-UNIQUE_MARKER line\n+CHANGED line\n", (string) $result->diff());
        $this->assertLessThan(3 * $size, $growth, $this->growthMessage($growth, $size));
    }

    /**
     * Overwriting does not need the old bytes, which were read only for the
     * preview: past the diff bound the write still lands, unread, and says so.
     */
    public function testWriteOverwriteOfALargeFileSkipsReadingThePreviousContents(): void
    {
        $size = $this->writeLargeFile('big.txt');
        $content = str_replace('UNIQUE_MARKER', 'CHANGED', (string) file_get_contents($this->root . '/big.txt'));
        $write = new Write($this->root);
        $args = ['id' => 'c', 'file_path' => 'big.txt', 'content' => $content, 'overwrite' => true];

        [$result, $growth] = $this->measure(static fn () => $write->execute($args));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('File overwritten: ', $result->content());
        $this->assertStringContainsString(
            '(previous ' . number_format($size) . ' bytes not diffed: over the 1,048,576-byte diff bound)',
            $result->content(),
        );
        $this->assertNull($result->diff());
        $this->assertSame($content, file_get_contents($this->root . '/big.txt'));
        $this->assertLessThan(3 * $size, $growth, $this->growthMessage($growth, $size));
    }

    public function testWriteOverwriteWithARaisedBoundDiffsALargeFileUnderThreeTimesItsSize(): void
    {
        $size = $this->writeLargeFile('big.txt');
        $content = str_replace('UNIQUE_MARKER', 'CHANGED', (string) file_get_contents($this->root . '/big.txt'));
        $write = new Write($this->root, maxDiffBytes: 32 * 1024 * 1024);
        $args = ['id' => 'c', 'file_path' => 'big.txt', 'content' => $content, 'overwrite' => true];

        [$result, $growth] = $this->measure(static fn () => $write->execute($args));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString("-UNIQUE_MARKER line\n+CHANGED line\n", (string) $result->diff());
        $this->assertLessThan(3 * $size, $growth, $this->growthMessage($growth, $size));
    }

    /**
     * A new file's diff is every line inserted, so it is the changed-region
     * bound, not the read bound, that keeps a large Write cheap.
     */
    public function testWriteOfALargeNewFileOmitsThePreview(): void
    {
        $content = self::largeContent();
        $size = strlen($content);
        $write = new Write($this->root);
        $args = ['id' => 'c', 'file_path' => 'new.txt', 'content' => $content];

        [$result, $growth] = $this->measure(static fn () => $write->execute($args));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('File created: ', $result->content());
        $this->assertStringContainsString('diff preview omitted', $result->content());
        $this->assertNull($result->diff());
        $this->assertSame($size, filesize($this->root . '/new.txt'));
        $this->assertLessThan(3 * $size, $growth, $this->growthMessage($growth, $size));
    }

    /**
     * replace_all at the first and last line makes the whole file the changed
     * region. The preview is omitted, but the model is still told how big a
     * change it made.
     */
    public function testAReplaceAllSpanningTheWholeFileOmitsThePreviewButCountsTheChange(): void
    {
        $lines = 30_000;
        $text = "EDGE\n";
        for ($i = 1; $i < $lines - 1; $i++) {
            $text .= "line $i\n";
        }
        $text .= "EDGE\n";
        file_put_contents($this->root . '/edges.txt', $text);
        $edit = new Edit($this->root, maxBytes: 32 * 1024 * 1024);

        $result = $edit->execute([
            'id' => 'c',
            'file_path' => 'edges.txt',
            'old_string' => 'EDGE',
            'new_string' => 'MOVED',
            'replace_all' => true,
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertNull($result->diff());
        $this->assertStringContainsString(
            '(changed region: +' . $lines . ' -' . $lines . ' lines; diff preview omitted, over the 20,000-line',
            $result->content(),
        );
        $this->assertStringStartsWith('MOVED', (string) file_get_contents($this->root . '/edges.txt'));
    }

    /**
     * @param callable(): \SugarCraft\Crush\Tools\ToolResult $call
     * @return array{0: \SugarCraft\Crush\Tools\ToolResult, 1: int}
     */
    private function measure(callable $call): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $result = $call();

        return [$result, memory_get_peak_usage() - $base];
    }

    private function growthMessage(int $growth, int $size): string
    {
        return sprintf('peak grew %.1f MB for a %.1f MB file (%.1fx)', $growth / 1048576, $size / 1048576, $growth / $size);
    }

    /** Writes the large fixture without holding it in memory, returns its size. */
    private function writeLargeFile(string $name): int
    {
        $handle = fopen($this->root . '/' . $name, 'wb');
        $this->assertNotFalse($handle);
        $chunk = self::rows(0, 10_000);
        $written = 0;
        $marker = false;
        while ($written < self::FILE_BYTES) {
            if (!$marker && $written >= self::FILE_BYTES / 2) {
                $written += (int) fwrite($handle, "UNIQUE_MARKER line\n");
                $marker = true;
            }
            $written += (int) fwrite($handle, $chunk);
        }
        fclose($handle);

        return $written;
    }

    private static function largeContent(): string
    {
        return str_repeat(self::rows(0, 10_000), intdiv(self::FILE_BYTES, strlen(self::rows(0, 10_000))));
    }

    private static function rows(int $from, int $to): string
    {
        $text = '';
        for ($i = $from; $i < $to; $i++) {
            $text .= sprintf("row %06d padding to a realistic line length\n", $i);
        }

        return $text;
    }
}
