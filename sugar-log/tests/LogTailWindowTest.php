<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\LogTailWindow;

/**
 * Pins the rules LogTailWindow shares with candy-code's server log tail
 * (`candy-code/src/Server/LogTail.php`): blank lines kept and counted, the
 * offset is the file size, the read is capped at the window with its first
 * line dropped, carriage returns stay, and a path that cannot be opened is
 * no lines at offset 0.
 */
final class LogTailWindowTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sugar-log-tailwindow-' . \getmypid() . '-' . \spl_object_id($this);
        \mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->dir . '/' . $entry;
            @\chmod($path, 0700);
            \is_dir($path) && !\is_link($path) ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($this->dir);
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $bytes);

        return $path;
    }

    public function testNewUsesTheServerLogTailWindow(): void
    {
        $tail = LogTailWindow::new('/some/log');

        self::assertSame('/some/log', $tail->path);
        self::assertSame(256 * 1024, LogTailWindow::WINDOW_BYTES);
        self::assertSame(LogTailWindow::WINDOW_BYTES, $tail->windowBytes);
    }

    public function testBlankAndWhitespaceOnlyLinesAreKeptAndCountTowardN(): void
    {
        $path = $this->file('blank.log', "a\n\n  \n\t\nb\n");

        self::assertSame([['', '  ', "\t", 'b'], 10], LogTailWindow::new($path)->lines(4));
        self::assertSame([["\t", 'b'], 10], LogTailWindow::new($path)->lines(2));
        self::assertSame([['a', '', '  ', "\t", 'b'], 10], LogTailWindow::new($path)->lines(50));
    }

    public function testTrailingNewlinesAtTheEndOfTheFileAreNotLines(): void
    {
        $path = $this->file('trailing.log', "a\nb\n\n\n");

        self::assertSame([['a', 'b'], 6], LogTailWindow::new($path)->lines(10));
    }

    public function testOffsetIsTheFileSizeWhateverNIs(): void
    {
        $bytes = "one\ntwo\nthree\n";
        $path = $this->file('offset.log', $bytes);

        foreach ([0, 1, 2, 3, 99] as $n) {
            [, $offset] = LogTailWindow::new($path)->lines($n);
            self::assertSame(\strlen($bytes), $offset, "n = $n");
        }
    }

    public function testOffsetCoversAFinalLineStillMissingItsNewline(): void
    {
        $path = $this->file('partial.log', "a\nb");

        self::assertSame([['a', 'b'], 3], LogTailWindow::new($path)->lines(5));
        self::assertSame([['b'], 3], LogTailWindow::new($path)->lines(1));
    }

    public function testZeroLinesAnswersNoLinesButStillTheOffset(): void
    {
        $path = $this->file('zero.log', "a\nb\n");

        self::assertSame([[], 4], LogTailWindow::new($path)->lines(0));
    }

    public function testWindowDropsTheFirstPartialLine(): void
    {
        // 15 bytes; a 7-byte window starts at byte 8, inside "bbbb".
        $path = $this->file('window.log', "aaaa\nbbbb\ncccc\n");

        self::assertSame([['cccc'], 15], LogTailWindow::new($path)->withWindowBytes(7)->lines(10));
    }

    public function testWindowStartingOnALineBoundaryStillDropsItsFirstLine(): void
    {
        // A 10-byte window starts at byte 5, exactly the start of "bbbb". The
        // server log tail drops that line too; one byte more would be needed
        // to tell it is whole.
        $path = $this->file('aligned.log', "aaaa\nbbbb\ncccc\n");

        self::assertSame([['cccc'], 15], LogTailWindow::new($path)->withWindowBytes(10)->lines(10));
    }

    public function testWindowCoveringTheWholeFileKeepsTheFirstLine(): void
    {
        $path = $this->file('whole.log', "aaaa\nbbbb\ncccc\n");

        self::assertSame([['aaaa', 'bbbb', 'cccc'], 15], LogTailWindow::new($path)->withWindowBytes(15)->lines(10));
        self::assertSame([['aaaa', 'bbbb', 'cccc'], 15], LogTailWindow::new($path)->withWindowBytes(1000)->lines(10));
    }

    public function testWindowWithNoNewlineAnswersNoLines(): void
    {
        $path = $this->file('oneline.log', \str_repeat('x', 100) . "\n");

        self::assertSame([[], 101], LogTailWindow::new($path)->withWindowBytes(20)->lines(10));
    }

    public function testDefaultWindowCapsTheRead(): void
    {
        // One line longer than the default window, then a short one: read in
        // full, the long line would come back; read through the window, its
        // start lies outside, so it is the dropped partial line.
        $long = \str_repeat('x', LogTailWindow::WINDOW_BYTES + 1024);
        $path = $this->file('big.log', "first\n" . $long . "\nlast\n");

        [$lines, $offset] = LogTailWindow::new($path)->lines(10);

        self::assertSame(['last'], $lines);
        self::assertSame(\strlen($long) + 12, $offset);
    }

    public function testDefaultWindowKeepsLinesWhollyInsideIt(): void
    {
        $line = \str_repeat('y', 1023);
        $count = (int) (LogTailWindow::WINDOW_BYTES / 1024) + 10;
        $path = $this->file('many.log', \str_repeat($line . "\n", $count));

        [$lines] = LogTailWindow::new($path)->lines(1000);

        // The window holds exactly 256 whole 1024-byte lines; the first is
        // dropped as the window's first line.
        self::assertCount((int) (LogTailWindow::WINDOW_BYTES / 1024) - 1, $lines);
        self::assertSame([$line], \array_values(\array_unique($lines)));
    }

    public function testTrailingCarriageReturnIsKept(): void
    {
        $path = $this->file('crlf.log', "a\r\nb\r\n\r\nc\r");

        self::assertSame([["a\r", "b\r", "\r", "c\r"], 10], LogTailWindow::new($path)->lines(10));
    }

    public function testEmptyFileHasNoLinesAtOffsetZero(): void
    {
        $path = $this->file('empty.log', '');

        self::assertSame([[], 0], LogTailWindow::new($path)->lines(10));
    }

    public function testFileOfOnlyNewlinesHasNoLines(): void
    {
        $path = $this->file('newlines.log', "\n\n\n");

        self::assertSame([[], 3], LogTailWindow::new($path)->lines(10));
    }

    public function testMissingFileHasNoLinesAtOffsetZero(): void
    {
        self::assertSame([[], 0], LogTailWindow::new($this->dir . '/absent.log')->lines(10));
    }

    public function testExistingFileThatCannotBeOpenedHasNoLinesAtOffsetZero(): void
    {
        $path = $this->file('locked.log', "secret\n");
        \chmod($path, 0000);
        \clearstatcache(true, $path);
        if (\is_readable($path)) {
            self::markTestSkipped('This user can read a mode 0000 file (root), so the open cannot fail.');
        }

        self::assertSame([[], 0], LogTailWindow::new($path)->lines(10));
    }

    public function testDirectoryHasNoLinesAtOffsetZero(): void
    {
        \mkdir($this->dir . '/sub');

        self::assertSame([[], 0], LogTailWindow::new($this->dir . '/sub')->lines(10));
    }

    public function testFifoIsNeverOpened(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is not available.');
        }
        $path = $this->dir . '/pipe';
        self::assertTrue(\posix_mkfifo($path, 0600));

        // Opening a fifo with no writer blocks, so reaching the assertion at
        // all shows it was refused before the open.
        self::assertSame([[], 0], LogTailWindow::new($path)->lines(10));
    }

    public function testSymlinkToARegularFileIsRead(): void
    {
        $target = $this->file('target.log', "a\nb\n");
        $link = $this->dir . '/link.log';
        \symlink($target, $link);

        self::assertSame([['a', 'b'], 4], LogTailWindow::new($link)->lines(10));
    }

    public function testNegativeCountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-1');

        LogTailWindow::new($this->dir . '/absent.log')->lines(-1);
    }

    public function testNonPositiveWindowThrows(): void
    {
        foreach ([0, -5] as $bytes) {
            try {
                LogTailWindow::new('/some/log')->withWindowBytes($bytes);
                self::fail("withWindowBytes($bytes) should throw");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString((string) $bytes, $e->getMessage());
            }
        }
    }

    public function testWithWindowBytesReturnsANewInstance(): void
    {
        $tail = LogTailWindow::new('/some/log');
        $smaller = $tail->withWindowBytes(64);

        self::assertNotSame($tail, $smaller);
        self::assertSame(LogTailWindow::WINDOW_BYTES, $tail->windowBytes);
        self::assertSame(64, $smaller->windowBytes);
        self::assertSame('/some/log', $smaller->path);
    }

    public function testWithPathReturnsANewInstance(): void
    {
        $tail = LogTailWindow::new('/some/log')->withWindowBytes(64);
        $moved = $tail->withPath('/other/log');

        self::assertNotSame($tail, $moved);
        self::assertSame('/some/log', $tail->path);
        self::assertSame('/other/log', $moved->path);
        self::assertSame(64, $moved->windowBytes);
    }

    public function testReadingDoesNotChangeTheInstance(): void
    {
        $path = $this->file('stable.log', "a\nb\n");
        $tail = LogTailWindow::new($path)->withWindowBytes(3);
        $before = clone $tail;

        $tail->lines(10);

        self::assertEquals($before, $tail);
    }
}
