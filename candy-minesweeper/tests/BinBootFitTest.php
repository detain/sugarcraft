<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Minesweeper\Tests\Support\PtyLaunch;

/**
 * Boot-fit contract (candy-minesweeper-001, critical) on the REAL binary,
 * under a `script -qec` pty of a known size. The refusal must come before
 * the alt screen is entered: a too-small launch exits 1 with a plain
 * message and paints no frame; a fitting launch boots and quits cleanly.
 */
final class BinBootFitTest extends TestCase
{
    public function testTooSmallTerminalRefusesBootWithPlainExitOne(): void
    {
        // Easy needs 17 rows; 12 offered → refuse BEFORE the alt screen.
        [$code, $output] = PtyLaunch::run(['--easy'], 12, 60);
        self::assertSame(1, $code, 'output was: ' . PtyLaunch::printable($output));
        self::assertStringContainsString('needs a terminal', $output);
        self::assertStringContainsString('17', $output);
        self::assertStringNotContainsString("\x1b[?1049h", $output, 'refusal must not enter the alt screen');
    }

    public function testFittingTerminalBootsAndQuits(): void
    {
        [$code, $output] = PtyLaunch::run(['--easy'], 30, 80, 'q');
        self::assertStringNotContainsString('needs a terminal', $output);
        self::assertStringContainsString("\x1b[?1049h", $output, 'fitting boot must enter the alt screen');
        self::assertSame(0, $code, 'output was: ' . PtyLaunch::printable($output));
    }
}
