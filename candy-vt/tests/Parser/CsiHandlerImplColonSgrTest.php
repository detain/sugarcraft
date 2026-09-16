<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Terminal;

/**
 * The renderer half of ECMA-48 colon extended-colours: the emulator's
 * SgrHandler keeps the RGB, while this pen stores palette indices and DROPS
 * truecolour to the default (documented in {@see CsiHandlerImplTest} and
 * VtParityTest::DIVERGENCE_NOTE). Dropping the COLOUR is fine — dropping the
 * GROUP is not. Before CsiHandlerImpl consulted Parser::subparams() in
 * sgrExtended()/sgrExtendedDiscard(), a six-slot colon group
 * (`1;38:2::255:0:0`) consumed only five flat slots and replayed the
 * trailing 0 as an independent SGR reset, wiping the bold that preceded it
 * — a pen the semicolon spelling keeps. Same references as the emulator
 * test: xterm consumes `next = item + have` regardless of colour success
 * (charproc.c:2144,2170), tmux routes the whole colon string through
 * input_csi_dispatch_sgr_colon() (input.c:2393-2396) so no sub-slot can
 * resurface as its own parameter.
 *
 * @see \SugarCraft\Vt\Tests\Handler\ColonExtendedColourTest (emulator half, full citations)
 */
final class CsiHandlerImplColonSgrTest extends TestCase
{
    private function cellAfter(string $csiWithPrintable): Cell
    {
        $t = Terminal::new(10, 2);
        $t->feed($csiWithPrintable . 'X');
        return $t->grid()->get(0, 0);
    }

    public function testColonTruecolourKeepsTheRestOfThePenLikeSemicolon(): void
    {
        $baseline = $this->cellAfter("\x1b[1;38;2;255;0;0m");
        $this->assertSame(
            [$baseline->fg, $baseline->attrs],
            [$this->cellAfter("\x1b[1;38:2::255:0:0m")->fg, $this->cellAfter("\x1b[1;38:2::255:0:0m")->attrs],
            '38:2::R:G:B must leave the palette pen exactly where 38;2;R;G;B does'
        );
        $this->assertSame(
            [$baseline->fg, $baseline->attrs],
            [$this->cellAfter("\x1b[1;38:2:16:255:0:0m")->fg, $this->cellAfter("\x1b[1;38:2:16:255:0:0m")->attrs],
            '38:2:CS:R:G:B (colour space 16) must match the semicolon form too'
        );
        $this->assertTrue(
            ($this->cellAfter("\x1b[1;38:2::255:0:0m")->attrs & Cell::ATTR_BOLD) === Cell::ATTR_BOLD,
            'bold must survive the colon truecolour group'
        );
    }

    public function testColonBackgroundKeepsTheRestOfThePenLikeSemicolon(): void
    {
        $baseline = $this->cellAfter("\x1b[1;48;2;255;0;0m");
        $cell = $this->cellAfter("\x1b[1;48:2::255:0:0m");
        $this->assertSame([$baseline->bg, $baseline->attrs], [$cell->bg, $cell->attrs], '48:2::R:G:B equals 48;2;R;G;B');
    }

    public function testColonIndexedSetsTheSamePaletteIndexAsSemicolon(): void
    {
        $this->assertSame(
            $this->cellAfter("\x1b[38;5;99m")->fg,
            $this->cellAfter("\x1b[38:5:99m")->fg,
            '38:5:N must set the same palette index as 38;5;N'
        );
        $this->assertSame(
            $this->cellAfter("\x1b[48;5;99m")->bg,
            $this->cellAfter("\x1b[48:5:99m")->bg,
            '48:5:N must set the same palette index as 48;5;N'
        );
    }

    public function testColonUnderlineColourGroupIsConsumedWhole(): void
    {
        $baseline = $this->cellAfter("\x1b[1;58;2;255;0;0m");
        foreach (['38' => "\x1b[1;58:2::255:0:0m", '16' => "\x1b[1;58:2:16:255:0:0m", 'idx' => "\x1b[1;58:5:9m"] as $tag => $seq) {
            $cell = $this->cellAfter($seq);
            $this->assertSame(
                [$baseline->fg, $baseline->attrs],
                [$cell->fg, $cell->attrs],
                "58 colon group ({$tag}) must consume exactly its own slots, like the semicolon form"
            );
        }
    }
}
