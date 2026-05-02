<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Util;

use CandyCore\Core\Util\Tty;
use PHPUnit\Framework\TestCase;

final class TtyTest extends TestCase
{
    public function testSizeFallsBackTo80x24(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new Tty($r);

        $prevCols = getenv('COLUMNS');
        $prevRows = getenv('LINES');
        putenv('COLUMNS');
        putenv('LINES');

        try {
            $size = $tty->size();
            $this->assertSame(80, $size['cols']);
            $this->assertSame(24, $size['rows']);
        } finally {
            if ($prevCols !== false) putenv('COLUMNS=' . $prevCols);
            if ($prevRows !== false) putenv('LINES='   . $prevRows);
            fclose($r);
        }
    }

    public function testSizeHonorsEnv(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new Tty($r);

        $prevCols = getenv('COLUMNS');
        $prevRows = getenv('LINES');
        putenv('COLUMNS=132');
        putenv('LINES=50');

        try {
            $size = $tty->size();
            $this->assertSame(132, $size['cols']);
            $this->assertSame(50,  $size['rows']);
        } finally {
            putenv('COLUMNS' . ($prevCols === false ? '' : '=' . $prevCols));
            putenv('LINES'   . ($prevRows === false ? '' : '=' . $prevRows));
            fclose($r);
        }
    }

    public function testIsTtyFalseForMemoryStream(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new Tty($r);
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testEnableAndRestoreRawModeNoOpOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new Tty($r);
        $tty->enableRawMode();
        $tty->restore();
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testOpenTtyReturnsNullOnWindows(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows-only branch');
        }
        $this->assertNull(Tty::openTty());
    }

    public function testOpenTtyReturnsPairOrNull(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Posix-only test');
        }
        $result = Tty::openTty();
        // CI sandboxes may not expose /dev/tty — accept either branch.
        if ($result === null) {
            $this->assertNull($result);
            return;
        }
        $this->assertCount(2, $result);
        [$in, $out] = $result;
        $this->assertIsResource($in);
        $this->assertIsResource($out);
        // Each side is opened separately and is independent.
        $this->assertNotSame($in, $out);
        fclose($in);
        fclose($out);
    }
}
