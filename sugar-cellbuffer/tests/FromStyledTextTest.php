<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Style;

/**
 * CB-05 — Buffer::fromStyledText() is the supported production parse door:
 * grapheme clusters, canonical display widths under the pair law, the full
 * Style::fromSgr vocabulary, and real OSC 8 anchors (both the CB-01
 * double-semicolon form and the id= form).
 */
final class FromStyledTextTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function dimensionProvider(): array
    {
        return ['zero width' => [0, 1], 'zero height' => [1, 0], 'neg w' => [-2, 3], 'neg h' => [3, -1]];
    }

    /**
     * @dataProvider dimensionProvider
     */
    public function testRejectsNonPositiveDimensions(int $w, int $h): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::fromStyledText('x', $w, $h);
    }

    public function testEmptyTextIsBlankFrame(): void
    {
        $b = Buffer::fromStyledText('', 3, 2);
        self::assertSame(3, $b->width());
        self::assertSame(2, $b->height());
        self::assertSame("   \n   ", $b->text());
    }

    public function testPlainTextIsRowDelimitedAndOverflowTruncates(): void
    {
        $b = Buffer::fromStyledText("AB\nCD", 2, 2);
        self::assertSame('AB' . "\n" . 'CD', $b->text());

        // No LF: overflow past the frame width is truncated (the frame
        // grammar this door parses is row-delimited by toAnsi(); wrapping
        // is the caller's line-splitting job, not this door's).
        $wrapped = Buffer::fromStyledText('ABCD', 2, 2);
        self::assertSame('AB' . "\n" . '  ', $wrapped->text());

        // Missing cells pad blank; past-frame rows truncate.
        $short = Buffer::fromStyledText('ABC', 2, 3);
        self::assertSame('AB' . "\n" . '  ' . "\n" . '  ', $short->text());
        $tall = Buffer::fromStyledText("A\nB\nC", 1, 2);
        self::assertSame('A' . "\n" . 'B', $tall->text());
    }

    public function testStyleAccumulatesAndResets(): void
    {
        $b = Buffer::fromStyledText("\x1b[1m\x1b[38;2;255;0;0mR\x1b[0mn", 2, 1);
        self::assertEquals(
            Cell::new('R', new Style(fg: 0xFF0000, attrs: Style::ATTR_BOLD)),
            $b->cellAt(0, 0),
        );
        self::assertEquals(Cell::new('n'), $b->cellAt(1, 0));
    }

    public function testGridRoundTripsThroughToAnsi(): void
    {
        // A styled reference inside the parse-closed set (truecolor, all
        // attributes, links, wide + multibyte + blank runes) must come back
        // CELL-IDENTICAL through encode -> parse.
        $bold = new Style(fg: 0x11EE33, bg: 0x2200BB, attrs: Style::ATTR_BOLD | Style::ATTR_ITALIC);
        $ul = new Style(fg: 0xAABBCC, ul: 0x0D0E0F, attrs: Style::ATTR_UNDERLINE);
        $ref = Buffer::new(12, 3)
            ->withCellAt(0, 0, Cell::new('中', $bold, new Hyperlink('https://s.test/a', '42'), 2))
            ->withCellAt(3, 0, Cell::new('x', $ul))
            ->withCellAt(5, 1, Cell::new('é', new Style(bg: 0x0A0B0C)))
            ->withCellAt(7, 1, Cell::new('#', new Style(bg: 0x0A0B0C)))
            ->withCellAt(0, 2, Cell::new('L', null, new Hyperlink('https://s.test/b')))
            ->withCellAt(4, 2, Cell::new('!'));

        $parsed = Buffer::fromStyledText($ref->toAnsi(), 12, 3);

        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 12; $col++) {
                self::assertTrue(
                    $ref->cellAt($col, $row)->equals($parsed->cellAt($col, $row)),
                    "cell ({$col},{$row}) must survive the round trip",
                );
            }
        }
    }

    public function testEncodeParseEncodeStabilityOnPaletteSlotSample(): void
    {
        // Slot/belt styles are minted through the parser itself so their
        // representation is parse-closed; the honest pin for this class is
        // byte stability of the wire, not hand-built grid identity.
        $pen = static fn (string $params): ?Style => Style::fromSgr($params);
        $ref = Buffer::new(10, 2)
            ->withCellAt(0, 0, Cell::new('a', $pen('1;31;44')))
            ->withCellAt(1, 0, Cell::new('b', $pen('101;38;5;208')))
            ->withCellAt(2, 0, Cell::new('漢', $pen('4;58;5;3'), null, 2))
            ->withCellAt(5, 0, Cell::new('c', $pen('0')))
            ->withCellAt(0, 1, Cell::new('d', null, new Hyperlink('https://x.test/1')))
            ->withCellAt(4, 1, Cell::new('e', $pen('7;38;2;1;2;3'), new Hyperlink('https://x.test/2', 'nine'), 1));

        $wire = $ref->toAnsi();
        self::assertSame($wire, Buffer::fromStyledText($wire, 10, 2)->toAnsi());
    }

    public function testOsc8TwoSemicolonFormAttachesLink(): void
    {
        $b = Buffer::fromStyledText("\x1b]8;;https://a.test/x\x1b\\L" . Hyperlink::CLOSE, 2, 1);
        self::assertEquals(new Hyperlink('https://a.test/x'), $b->cellAt(0, 0)->link());
        self::assertNull($b->cellAt(1, 0)->link(), 'the close frame must detach again');
    }

    public function testOsc8IdFormAttachesLink(): void
    {
        $b = Buffer::fromStyledText("\x1b]8;id=7;https://a.test/x\x1b\\L" . Hyperlink::CLOSE, 1, 1);
        self::assertEquals(new Hyperlink('https://a.test/x', '7'), $b->cellAt(0, 0)->link());
    }

    public function testMalformedLinkFramesKeepThePreviousLink(): void
    {
        // Unknown params grammar and control-bearing URIs cannot describe
        // an anchor: the frames are ignored (no guessing), the good link
        // stays live across them until a real close.
        $good = "\x1b]8;;https://a.test/keep\x1b\\";
        $junk = "\x1b]8;bogus;https://a.test/ignore\x1b\\";
        $cBad = "\x1b]8;;ht\x01tp://a.test/ignore\x1b\\";
        $b = Buffer::fromStyledText($good . 'A' . $junk . 'B' . $cBad . 'C', 3, 1);

        foreach ([0, 1, 2] as $col) {
            self::assertEquals(
                new Hyperlink('https://a.test/keep'),
                $b->cellAt($col, 0)->link(),
                "col {$col}: junk OSC 8 legs must not disturb the live anchor",
            );
        }
    }

    public function testNonSgrFramesDepositNoRunes(): void
    {
        // The CB-22 scanner law, now shared by this door too.
        self::assertSame('xy        ', Buffer::fromStyledText("x\x1b[2Jy", 10, 1)->text());
        self::assertSame('AB  ', Buffer::fromStyledText("\x1b[?25lAB\x1b[0m", 4, 1)->text());
        self::assertSame('A', Buffer::fromStyledText("AB\x1b[3;7HQ", 1, 1)->text(), 'addressing is dropped and overflowing cells truncate');

        // Unterminated CSI swallows the rest of the stream (and the
        // trailing blank cell proves the swallowed bytes painted nothing).
        self::assertSame('AB ', Buffer::fromStyledText("AB\x1b[38", 3, 1)->text());
        self::assertSame('AB ', Buffer::fromStyledText("AB\x1b]8;id=1;https://x", 3, 1)->text());
    }

    public function testStrayControlsAreSkippedNotCellified(): void
    {
        self::assertSame('AB', Buffer::fromStyledText("A\x07\x1fB\x7f", 2, 1)->text());
    }

    public function testWideStraddleDegradesAtTheLastColumn(): void
    {
        $b = Buffer::fromStyledText('a漢', 2, 1);
        self::assertSame('a', $b->cellAt(0, 0)->rune());
        self::assertTrue($b->cellAt(1, 0)->equals(Cell::blank()), 'straddling wide glyph degrades to blank, never overruns the row');

        // And toAnsi() of the parsed frame stays within the width.
        $line = explode("\n", $b->toAnsi())[0];
        self::assertSame('a ', preg_replace('/\x1b\[[0-9;]*m/', '', $line), 'row emits exactly the frame width: the letter plus the degraded blank');
    }

    public function testMultibyteContentNeverShiftsAttribution(): void
    {
        // sugar-flexbox finding-035 class: a multibyte separator between
        // styled runs must not slide the pen off the cells it belongs to.
        $b = Buffer::fromStyledText("\x1b[38;2;0;0;255m│中x", 3, 1);
        $blue = new Style(fg: 0x0000FF);
        self::assertEquals(Cell::new('│', $blue), $b->cellAt(0, 0));
        self::assertEquals(Cell::new('中', $blue, null, 2), $b->cellAt(1, 0));
        self::assertEquals(Cell::continuation(), $b->cellAt(2, 0));
    }

    public function testCombiningSequenceFoldsToOneCellWhereClustersExist(): void
    {
        $folds = \function_exists('grapheme_str_split') || \class_exists(\IntlBreakIterator::class);
        $b = Buffer::fromStyledText("e\u{0301}z", $folds ? 2 : 3, 1);

        if ($folds) {
            self::assertSame("e\u{0301}", $b->cellAt(0, 0)->rune(), 'UAX #29 cluster rides one cell');
            self::assertSame('z', $b->cellAt(1, 0)->rune());
        } else {
            // Documented codepoint degradation (mb ladder): base and mark
            // land on separate cells, but the parse stays width-correct.
            self::assertSame('e', $b->cellAt(0, 0)->rune());
            self::assertSame("\u{0301}", $b->cellAt(1, 0)->rune());
        }
    }

    public function testEmptySgrFrameResetsThePen(): void
    {
        $b = Buffer::fromStyledText("\x1b[31mA\x1b[mB", 2, 1);
        self::assertNotNull($b->cellAt(0, 0)->style());
        self::assertNull($b->cellAt(1, 0)->style(), 'empty params is the reset form');
    }
}
