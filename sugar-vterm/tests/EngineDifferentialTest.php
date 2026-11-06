<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Screen\Screen;
use SugarCraft\Vterm\Terminal as RendererTerminal;
use SugarCraft\Vterm\Terminal\Terminal as FullTerminal;

/**
 * Cross-engine differential (audit M5).
 *
 * sugar-vterm ships TWO independent CSI engines: the emulator
 * (Handler\ScreenHandler) and the renderer (Parser\CsiHandlerImpl behind
 * sugar-ansi-parser's HandlerAdapter). Historically the renderer lacked the
 * emulator's wide-partner clearing, so the two DIVERGED silently on exactly
 * the hostile cases (a glyph over one half of a wide pair): candy-vcr's
 * replay renders drifted from live emulation with ghost columns that
 * inflate Width::of past the grid.
 *
 * Each case feeds identical bytes through both 20x5 engines and compares
 * the resulting grids CELL BY CELL, encoding a wide-continuation half as
 * '·' so a ghost (unblanked continuation) is visibly different from the
 * emulator's blanked partner. Plain graphemes carry their stored combining
 * marks so both text channels are policed together.
 *
 * Where an engine legitimately does not implement a sequence at all, the
 * case stays out of this corpus (the README coverage table is the ledger
 * for those asymmetries). TAB handling is the one DELIBERATE divergence
 * kept OUT here on purpose: the emulator advances to the next tab-stop
 * (defaults every 8 columns, HTS/CSI g/Z/I respected through TabHandler),
 * while the renderer documents tab-stop modelling as out of scope —
 * CsiHandlerImpl::cht/tbc/cbt take single-column steps or no-ops. Adding
 * tab-stop state to the renderer is a separate feature, not a ghost-class
 * divergence this guard should police.
 */
final class EngineDifferentialTest extends TestCase
{
    private const COLS = 20;
    private const ROWS = 5;

    /**
     * @return array<string, array{0: string}>
     */
    public static function corpusProvider(): array
    {
        return [
            'plain text' => ['hello world'],
            'sgr + text' => ["\x1b[1;31mred\x1b[0m plain"],
            'wide pairs' => ['ab漢cd'],
            // Narrow glyph onto the TAIL half of a wide pair: the emulator
            // blanks the orphaned base; a renderer without the twin leaves
            // the base painted and diverges.
            'glyph over tail' => ["漢\x1b[1;2Hy"],
            // Narrow glyph onto the HEAD half: the emulator blanks the
            // partner continuation; a renderer without the twin leaves a
            // ghost column.
            'glyph over head' => ["漢漢\x1b[1;1Ha\x1b[1;3Hb"],
            'double pair wipe' => ["漢漢漢\x1b[1;1Habcd"],
            'cup and text' => ["\x1b[3;10Hplaced"],
            'erase line' => ["abcdef\r\x1b[2D\x1b[K"],
            'erase display' => ["fill1\r\nfill2\x1b[2J\x1b[Htail"],
            // ED mode 3: the emulator drains its scrollback ring, the
            // renderer has no ring and arms 0/1/2 only — the legitimate
            // shared outcome is that neither visible grid changes. Pinned
            // so neither engine ever starts blanking rows on mode 3.
            'erase scrollback (ED 3)' => ["fill1\r\nfill2\x1b[3J"],
            'insert line' => ["row1\r\nrow2\x1b[1;1H\x1b[1Lgap"],
            'delete line' => ["row1\r\nrow2\r\nrow3\x1b[1;1H\x1b[1M"],
            'scroll up' => ["a\r\nb\r\nc\x1b[3;1H\x1b[1S"],
            'scroll down' => ["a\r\nb\r\nc\x1b[1;1H\x1b[1T"],
            'insert chars' => ["ab\x1b[1;1H\x1b[2@XY"],
            'delete chars' => ["abcdef\x1b[1;1H\x1b[2P"],
            'carriage return' => ["overflowline\rX"],
            'decawm wrap' => [str_repeat('x', 25)],
            'combining mark' => ["e\xcc\x81x"],
            'mark over wide tail' => ["漢\xcc\x81done"],
            'backspace then write' => ["ab\x1b[Dc"],
            'origin clamp' => ["\x1b[99;99Hcorner"],
            'reset then type' => ["\x1bcafter reset"],
        ];
    }

    /**
     * Cell-by-cell encoding: continuation halves become '·' so ghosts and
     * blanks never alias; everything else is grapheme + stored marks.
     *
     * @return list<string>
     */
    private static function encode(Screen $screen): array
    {
        $rows = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $line = '';
            for ($c = 0; $c < self::COLS; $c++) {
                $cell = $screen->cell($r, $c);
                $line .= $cell->continuation ? '·' : $cell->grapheme . $cell->combining;
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * @dataProvider corpusProvider
     */
    public function testBothEnginesProduceTheSameGrid(string $bytes): void
    {
        $emulator = FullTerminal::new(self::COLS, self::ROWS);
        $emulator->feed($bytes);

        $renderer = RendererTerminal::new(self::COLS, self::ROWS);
        $renderer->feed($bytes);

        self::assertSame(
            self::encode($emulator->screen()),
            self::encode(Screen::fromBuffer($renderer->snapshot()->grid)),
            'engines diverged on: ' . bin2hex($bytes)
        );
    }
}
