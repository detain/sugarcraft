<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\ReadLedger;

/**
 * ISS-060: Read sniffs binaries on a bounded head read — a PNG answers with
 * an image note, not mojibake lines — and the line-counting pass is bounded
 * too: past the scan budget the footer's count is a floor (`of ≥N`), an
 * offset inside the real file is not refused against the floor, and the
 * ledger records a null hash instead of a prefix pretending to be the whole.
 *
 * The refusal pair matters: test 7 (floor, no refusal) pins the new arm and
 * test 8 (exact count still refuses) pins that the arm did not arrive by
 * deleting the guard.
 */
final class ReadBinaryScanTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_readbinary_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $walk = is_dir($this->dir)
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            )
            : new \ArrayIterator([]);
        foreach ($walk as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->dir);
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    // =========================================================================
    // The binary sniff
    // =========================================================================

    public function testAPngAnswersWithAnImageNoteNotAPage(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 200) . 'IHDR-not-really';
        $path = $this->file('logo.png', $png);

        $result = (new Read())->execute(['file_path' => $path]);

        self::assertFalse($result->isError(), 'a binary image is an answer, not a failure');
        self::assertStringContainsString('Binary image (image/png)', $result->content());
        self::assertStringContainsString(number_format(strlen($png)) . ' bytes', $result->content());
        self::assertStringNotContainsString('1: ', $result->content(), 'the mojibake page must not be produced at all');
    }

    public function testAnElfBinaryAnswersWithTheNulNote(): void
    {
        $path = $this->file('tool', "\x7fELF\x02\x01\x01\x00" . str_repeat("\x00", 128) . "\x01\x02bin");

        $result = (new Read())->execute(['file_path' => $path]);

        self::assertFalse($result->isError());
        self::assertStringContainsString('Binary file', $result->content());
        self::assertStringContainsString('NUL byte', $result->content());
        self::assertStringNotContainsString('1: ', $result->content());
    }

    public function testPlainTextStillComesBackAsNumberedLines(): void
    {
        $path = $this->file('a.txt', "hello\nworld\n");

        self::assertSame('1: hello' . "\n" . '2: world', (new Read())->execute(['file_path' => $path])->content());
    }

    public function testAnEmptyFileIsStillText(): void
    {
        $path = $this->file('empty.txt', '');

        self::assertSame('', (new Read())->execute(['file_path' => $path])->content());
    }

    /**
     * The sniff is a HEAD read, not a promise about the whole file: a NUL at
     * byte 10,000 leaves the first 8 KiB honest text. Widening the sniff to
     * the whole file would be the O(file) scan this issue's other half is
     * busy removing, on every single read.
     */
    public function testANulBeyondTheSniffHeadIsNotSniffed(): void
    {
        $path = $this->file('far.txt', str_repeat("a\n", 5_000) . "\x00" . "tail\n");

        $content = (new Read())->execute(['file_path' => $path, 'limit' => 2])->content();

        self::assertStringContainsString('1: a', $content);
        self::assertStringNotContainsString('Binary file', $content);
    }

    // =========================================================================
    // The bounded line-count pass
    // =========================================================================

    /**
     * A file longer than the scan budget pages from the front with a floor
     * count. Nine MiB of nine-byte lines is deliberately just past the
     * eight-MiB budget so the `≥` can only come from the cap, never from
     * fixture size arithmetic drifting under it.
     */
    public function testALongFilePagesWithAFloorCount(): void
    {
        $lines = 1_048_576; // 9,437,184 bytes at "abcdefgh\n"
        $path = $this->file('big.txt', str_repeat("abcdefgh\n", $lines));

        $content = (new Read())->execute(['file_path' => $path])->content();

        self::assertMatchesRegularExpression('/\[lines 1-\d+ of ≥\d+ — call Read with offset=\d+ to continue\]$/', $content);
        self::assertStringStartsWith('1: abcdefgh', $content);
    }

    public function testAnOffsetPastTheFloorButInsideTheFileIsNotRefused(): void
    {
        $lines = 1_048_576;
        $path = $this->file('big.txt', str_repeat("abcdefgh\n", $lines));
        // The floor sits at roughly (8 MiB + one 64 KiB block) / 9 bytes;
        // 1,000,000 is provably above that floor and below the true count,
        // so only the exact-count world of the past refused it.
        $offset = 1_000_000;

        $content = (new Read())->execute(['file_path' => $path, 'offset' => $offset, 'limit' => 2])->content();

        self::assertStringNotContainsString('past the end', $content);
        self::assertStringStartsWith("{$offset}: abcdefgh", $content);
    }

    /**
     * The negative control of the pair: within the budget the count is exact
     * and the refusal is alive.
     */
    public function testAnOffsetPastAnExactCountIsStillRefused(): void
    {
        $path = $this->file('small.txt', implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 10))) . "\n");

        $result = (new Read())->execute(['file_path' => $path, 'offset' => 50]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('past the end', $result->content());
        self::assertStringContainsString('10 lines', $result->content());
    }

    public function testACappedScanLeavesTheLedgerRestingOnTheSignature(): void
    {
        $lines = 1_048_576;
        $path = $this->file('big.txt', str_repeat("abcdefgh\n", $lines));
        $ledger = new ReadLedger();
        $tool = new Read(readLedger: $ledger);

        $tool->execute(['file_path' => $path]);

        // Bytes that are NOT the file's, offered to the staleness check:
        // with a recorded hash this reads 'modified', because the hash of
        // these bytes cannot equal the recorded one. A null hash takes the
        // signature arm instead, and the signature — the same untouched
        // file on disk — says nothing changed.
        self::assertNull($ledger->staleness($path, 'pretending the bytes changed'), 'a capped scan recorded a prefix hash as if it were the whole file');
    }
}
