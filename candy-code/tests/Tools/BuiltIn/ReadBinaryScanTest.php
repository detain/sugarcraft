<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\ReadLedger;

/**
 * ISS-060 + SHL-3 T1: Read sniffs binaries on a bounded head read — a PNG
 * within the display cap rides the result as image bytes with a caption
 * (never mojibake lines), a NUL-headed file answers with the note — and the
 * line-counting pass is bounded too: past the scan budget the footer's count
 * is a floor (`of ≥N`), an offset inside the real file is not refused against
 * the floor, and the ledger records a null hash instead of a prefix
 * pretending to be the whole.
 *
 * THE IMAGE POLARITY FLIPPED HERE, deliberately: the pre-SHL-3 pins asserted
 * a PNG answered with a refusal note ("not shown as text"); tier 1 routes
 * those bytes to the pre-existing human-visible ToolResult image channel, so
 * the same fixtures now assert the image-bearing result. The non-image (NUL)
 * refusal rows stay byte-true, and over-cap images keep the old refusal —
 * the flip is the cap's inside, not a deletion of the notice.
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

    private function file(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    // =========================================================================
    // The binary sniff
    // =========================================================================

    public function testAPngWithinTheCapRidesTheResultAsAnImageNotAPage(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 200) . 'IHDR-not-really';
        $path = $this->file('logo.png', $png);

        $result = (new Read())->execute(['file_path' => $path]);

        self::assertFalse($result->isError(), 'an image read is an answer, not a failure');
        self::assertTrue($result->hasImage(), 'the bytes must ride the human-visible channel');
        self::assertSame($png, $result->imageBytes(), 'the whole file, uncut');
        self::assertSame($path, $result->imagePath());
        self::assertIsString($result->imageProtocol(), 'the probe-once mosaic protocol rides along, as GenerateImage builds it');
        self::assertStringContainsString('Binary image (image/png)', $result->content());
        self::assertStringContainsString(number_format(strlen($png)) . ' bytes', $result->content());
        self::assertStringContainsString($path, $result->content(), 'the caption names the file');
        self::assertStringNotContainsString('1×1', $result->content(), 'a forged header cannot answer dimensions — and must not fake any');
        self::assertStringNotContainsString('not shown as text', $result->content(), 'the old refusal is gone for carried images');
        self::assertStringNotContainsString('1: ', $result->content(), 'the mojibake page must not be produced at all');
    }

    /**
     * A header a real decoder can answer gets its dimensions in the caption:
     * this is a genuine 1x1 PNG (base64 of the smallest lossless one), not
     * the forged magic-only fixture above.
     */
    public function testARealPngCaptionCarriesItsDimensions(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $path = $this->file('pixel.png', $png);

        $result = (new Read())->execute(['file_path' => $path]);

        self::assertTrue($result->hasImage());
        self::assertStringContainsString('1×1, ' . number_format(strlen($png)) . ' bytes', $result->content());
        self::assertStringContainsString('human-visible tool-result channel', $result->content());
    }

    /**
     * The other half of the flip's honesty: past the cap the old note stays,
     * because a truncated PNG is not a PNG — the same doctrine (and the same
     * constant) FileMentions applies to @-mention attachments. Boundary
     * pinned from both sides: AT the cap carries, ONE OVER refuses.
     */
    public function testAnImageOverTheDisplayCapKeepsTheRefusalNote(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n" . str_repeat('a', FileMentions::IMAGE_MAX_BYTES - 8 + 1);
        $path = $this->file('huge.png', $bytes);

        $result = (new Read())->execute(['file_path' => $path]);

        self::assertFalse($result->isError(), 'the note is still an answer, not a failure');
        self::assertFalse($result->hasImage(), 'over-cap bytes are refused whole, never clipped to fit');
        self::assertStringContainsString('Binary image (image/png)', $result->content());
        self::assertStringContainsString(number_format(strlen($bytes)) . ' bytes', $result->content());
        self::assertStringContainsString(number_format(FileMentions::IMAGE_MAX_BYTES) . '-byte display cap', $result->content());
    }

    public function testAnImageExactlyAtTheCapStillRidesTheResult(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n" . str_repeat('a', FileMentions::IMAGE_MAX_BYTES - 8);
        $path = $this->file('edge.png', $bytes);

        $result = (new Read())->execute(['file_path' => $path]);

        self::assertTrue($result->hasImage(), 'the cap refuses OVER it, not AT it');
        self::assertSame(strlen($bytes), strlen((string) $result->imageBytes()));
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
