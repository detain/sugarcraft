<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Terminal;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for the rendered cell grid.
 *
 * vterm-31 (finding sugar-vterm-016): the original two goldens baselined
 * nothing that mattered — they captured the concatenated CHARACTERS of the
 * grid, so a renderer that discarded every SGR attribute (colour, bold,
 * truecolour, hyperlinks) stayed green. These baselines are the full cell
 * grid with rendition bytes: each cell carries grapheme, fg/bg (palette or
 * truecolour), the attribute bitfield, the DECDHL rendition, the OSC 8
 * hyperlink URI and any combining marks. Dropping or mangling any of them
 * reddens the golden — mutation-proven in review.
 *
 * Format (deterministic, one cell per line):
 *   #<cols>x<rows> header, then per row a `r<N>` marker and its cells.
 *   Cell: <grapheme>|<fg>|<bg>|a<attrs-hex>|r<rendition>|h<uri|->|c<comb-hex|->
 *   fg/bg: `12` palette slot, or `#rrggbb` when the truecolour field is set.
 *   Continuation halves of wide glyphs render as `·CONT` (their rendition
 *   fields are still dumped — a half-cell with a colour is a bug to pin).
 *
 * Note: sugar-vterm is a parser (ANSI bytes -> Buffer). The "render" here
 * is the parsed cell-grid state after feeding a byte sequence.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    /**
     * Attribute-aware baseline of the SGR rainbow: RGB text plus a bold
     * yellow tail. The rendition bytes in the golden are the point — the
     * old text-only file could not tell this feed apart from no SGR at all.
     */
    public function testTerminalParsesSgrRainbowToGrid(): void
    {
        $ansi = "\x1b[31mR\x1b[32mG\x1b[34mB\x1b[0m\x1b[1;33mBold\x1b[0mZ";
        $t = Terminal::new(10, 3);
        $t->feed($ansi);

        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/terminal-parsed-text.golden',
            self::dumpGrid($t->grid()),
        );

        // Independent of the file: the pen truth, so an update-and-commit
        // re-baseline cannot quietly move the goalposts.
        $cells = [];
        for ($c = 0; $c < 10; $c++) {
            $cells[] = $t->grid()->cell(0, $c);
        }
        self::assertSame([1, 2, 4], [$cells[0]->fg, $cells[1]->fg, $cells[2]->fg], 'R/G/B keep their colours');
        self::assertSame(7, $cells[7]->fg, 'the post-reset Z is painted with the default fg');
        self::assertNotSame(0, $cells[4]->attrs & Cell::ATTR_BOLD, 'bold survives the compound SGR');
        self::assertSame(3, $cells[4]->fg, 'yellow tail');
        self::assertSame(0, $cells[7]->attrs & Cell::ATTR_BOLD, 'CSI 0 drops bold again');
    }

    /**
     * The 2J clear-screen baseline: a concrete blank-grid SHAPE (rows x cols
     * of default-pen space cells) — the old file only ever asserted the
     * character dump was non-empty, which spaces satisfy trivially.
     */
    public function testTerminalParsesCursorMovements(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("x"); // dirty a cell first: 2J must actually blank it
        $t->feed("\x1b[2J");

        $grid = $t->grid();
        foreach (range(0, 2) as $r) {
            foreach (range(0, 9) as $c) {
                $cell = $grid->cell($r, $c);
                self::assertSame(' ', $cell->grapheme, "cleared cell ($r,$c)");
                self::assertSame(7, $cell->fg, "cleared cell ($r,$c) carries no colour");
                self::assertSame(0, $cell->attrs, "cleared cell ($r,$c) carries no attrs");
            }
        }

        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/terminal-cleared.golden',
            self::dumpGrid($grid),
        );
    }

    public function testWideEraseBoundaryGolden(): void
    {
        // vterm-13 (NEW golden, disclosed): EL starting on a wide glyph's
        // continuation takes the base with it, and DCH on a continuation
        // deletes the whole pair atomically — the grid must hold no orphaned
        // halves at either erase boundary. Continuations render as '·'.
        $t = Terminal::new(10, 2);
        $t->feed("漢ab\x1b[1;2H\x1b[K");
        $t->feed("\x1b[2;1H漢cd\x1b[2;2H\x1b[P");

        $grid = $t->grid();
        $output = '';
        for ($r = 0; $r < $grid->rows; $r++) {
            for ($c = 0; $c < $grid->cols; $c++) {
                $cell = $grid->cell($r, $c);
                $output .= $cell->continuation ? '·' : $cell->grapheme;
            }
            $output .= "\n";
        }

        $this->assertSame(2, substr_count($output, "\n"), 'two grid rows, newline-terminated');
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/wide-erase-boundary.golden',
            $output,
        );
    }

    /**
     * Full-grid rendition dump — the oracle the goldens baseline against.
     */
    private static function dumpGrid(Buffer $grid): string
    {
        $out = '#' . $grid->cols . 'x' . $grid->rows . "\n";
        for ($r = 0; $r < $grid->rows; $r++) {
            $out .= "r$r\n";
            for ($c = 0; $c < $grid->cols; $c++) {
                $cell = $grid->cell($r, $c);
                $fg = $cell->fgTruecolor !== null ? '#' . dechex($cell->fgTruecolor) : (string) $cell->fg;
                $bg = $cell->bgTruecolor !== null ? '#' . dechex($cell->bgTruecolor) : (string) $cell->bg;
                $out .= ($cell->continuation ? '·CONT' : $cell->grapheme)
                    . '|' . $fg . '|' . $bg
                    . '|a' . dechex($cell->attrs)
                    . '|r' . $cell->rendition->value
                    . '|h' . ($cell->hyperlink === null ? '-' : $cell->hyperlink->uri)
                    . '|c' . ($cell->combining === '' ? '-' : bin2hex($cell->combining))
                    . "\n";
            }
        }
        return $out;
    }
}
