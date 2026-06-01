<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use SugarCraft\Vt\Terminal;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output.
 *
 * These tests capture the byte-exact output of render() methods
 * to detect unintended changes to terminal output.
 *
 * Note: candy-vt is primarily a parser (ANSI bytes -> CellGrid).
 * The "render" here is the parsed cell grid state after feeding
 * a sequence of ANSI bytes.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testTerminalParsesSgrRainbowToGrid(): void
    {
        // Feed ANSI bytes for colored characters
        $ansi = "\x1b[31mR\x1b[32mG\x1b[34mB\x1b[0m\x1b[1;33mBold\x1b[0m";
        $t = Terminal::new(10, 3);
        $t->feed($ansi);

        // Get the parsed content as bytes from the grid
        $grid = $t->grid();
        $output = '';
        for ($r = 0; $r < $grid->rows; $r++) {
            for ($c = 0; $c < $grid->cols; $c++) {
                $cell = $grid->get($r, $c);
                if ($cell->char !== "\0" && $cell->char !== ' ') {
                    $output .= $cell->char;
                }
            }
        }

        // The parsed text content (without ANSI codes since we capture cells)
        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/terminal-parsed-text.golden',
            $output,
        );
    }

    public function testTerminalParsesCursorMovements(): void
    {
        $ansi = "\x1b[2J";  // Clear screen
        $t = Terminal::new(10, 3);
        $t->feed($ansi);

        // After clear screen, grid should be empty (or have default cells)
        $grid = $t->grid();
        $output = '';
        for ($r = 0; $r < min(3, $grid->rows); $r++) {
            for ($c = 0; $c < min(10, $grid->cols); $c++) {
                $cell = $grid->get($r, $c);
                $output .= $cell->char;
            }
            $output .= "\n";
        }

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/terminal-cleared.golden',
            $output,
        );
    }
}
