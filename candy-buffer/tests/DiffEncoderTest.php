<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;
use SugarCraft\Buffer\Diff\EraseRunOp;
use SugarCraft\Buffer\Diff\MoveCursorOp;
use SugarCraft\Buffer\Diff\RepeatRunOp;
use SugarCraft\Buffer\Diff\SetCellOp;
use SugarCraft\Buffer\Diff\SetHyperlinkOp;
use SugarCraft\Buffer\Diff\SetStyleOp;
use SugarCraft\Buffer\Hyperlink;
use SugarCraft\Buffer\Style;

final class DiffEncoderTest extends TestCase
{
    private DiffEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new DiffEncoder();
    }

    public function testEncodeEmptyOps(): void
    {
        $bytes = $this->encoder->encode([]);

        $this->assertSame('', $bytes);
    }

    public function testEncodeMoveCursorOp(): void
    {
        $ops = [new MoveCursorOp(4, 2)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[3;5H", $bytes);
    }

    public function testEncodeMoveCursorSamePositionIsNoOp(): void
    {
        $ops = [
            new MoveCursorOp(0, 0),
            new MoveCursorOp(0, 0),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame('', $bytes);
    }

    public function testEncodeMoveCursorAlreadyAtPositionFromDifferentCursor(): void
    {
        // Each encode() call resets state, so subsequent calls are independent.
        // Here the second call starts from (1,1) and moves to (3,2).
        $encoder = new DiffEncoder();
        $encoder->encode([new MoveCursorOp(3, 2)]);
        $bytes = $encoder->encode([new MoveCursorOp(3, 2)]);

        $this->assertSame("\x1b[3;4H", $bytes);
    }

    public function testEncodeRepeatRunOp(): void
    {
        $ops = [
            new SetCellOp([Cell::new('A')]),
            new RepeatRunOp('A', 3),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("A\x1b[3b", $bytes);
    }

    public function testEncodeRepeatRunOpZeroCount(): void
    {
        $ops = [
            new SetCellOp([Cell::new('X')]),
            new RepeatRunOp('X', 0),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("X", $bytes);
    }

    public function testEncodeRepeatRunFallbackWhenRuneMismatch(): void
    {
        $encoder = new DiffEncoder();
        $encoder->encode([new SetCellOp([Cell::new('A')])]);
        $bytes = $encoder->encode([new RepeatRunOp('X', 2)]);

        $this->assertSame("\x1b[2b", $bytes);
    }

    public function testEncodeRepeatRunEmitsRepSequence(): void
    {
        $ops = [
            new SetCellOp([Cell::new('A')]),
            new RepeatRunOp('A', 3),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("A\x1b[3b", $bytes);
    }

    public function testEncodeSetStyleOp(): void
    {
        $ops = [new SetStyleOp(Style::bold())];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[0;1m", $bytes);
    }

    public function testEncodeSetStyleOpNullResets(): void
    {
        $ops = [new SetStyleOp(null)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[0m", $bytes);
    }

    public function testEncodeSetHyperlinkOpOpen(): void
    {
        $link = Hyperlink::new('https://example.com');
        $ops = [new SetHyperlinkOp($link)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b]8;https://example.com\x1b\\\x1b]8;;\x1b\\", $bytes);
    }

    public function testEncodeSetHyperlinkOpOpenWithId(): void
    {
        $link = Hyperlink::new('https://example.com', 'myid');
        $ops = [new SetHyperlinkOp($link)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b]8;myid;https://example.com\x1b\\\x1b]8;;\x1b\\", $bytes);
    }

    public function testEncodeSetHyperlinkOpCloseWithNoOpenLinkIsNoOp(): void
    {
        $ops = [new SetHyperlinkOp(null)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame('', $bytes);
    }

    public function testEncodeClosesHyperlinkAtEnd(): void
    {
        $link = Hyperlink::new('https://example.com');
        $ops = [
            new MoveCursorOp(0, 0),
            new SetHyperlinkOp($link),
            new SetCellOp([Cell::new('X')]),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b]8;https://example.com\x1b\\\x1b]8;;\x1b\\X", $bytes);
    }

    public function testEncodeSgrTransitionIsMinimal(): void
    {
        $style = Style::bold();
        $cell1 = Cell::new('A', $style);
        $cell2 = Cell::new('B', $style);
        $ops = [
            new SetCellOp([$cell1]),
            new SetCellOp([$cell2]),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[0;1mAB", $bytes);
    }

    public function testEncodeSgrDifferentiatesBoldItalic(): void
    {
        $bold = Style::new(null, null, Style::ATTR_BOLD);
        $italic = Style::new(null, null, Style::ATTR_ITALIC);
        $ops = [
            new SetCellOp([Cell::new('B', $bold)]),
            new SetCellOp([Cell::new('I', $italic)]),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[0;1mB\x1b[0;3mI", $bytes);
    }

    public function testEncodeWideCharAdvancesCursorByWidth2(): void
    {
        $cell = Cell::new('中', null, null, 2);
        $ops = [new SetCellOp([$cell])];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame('中', $bytes);
    }

    public function testEncodeCompositeStyle(): void
    {
        $style = Style::new(0x123456, 0xABCDEF, Style::ATTR_BOLD | Style::ATTR_UNDERLINE);
        $cell = Cell::new('S', $style);
        $ops = [new SetCellOp([$cell])];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[0;38;2;18;52;86;48;2;171;205;239;1;4mS", $bytes);
    }

    public function testRepeatRunWideAdvancesCursorByWidth(): void
    {
        // RepeatRunOp('中', 2, 2): 2 repeats × width 2 = 4 cursor positions advanced.
        // After the first SetCellOp (width-2 cell '中'), cursorCol is at 3 (1-based).
        // The REP should advance by 2*2=4, landing at 7 (1-based) before the MoveCursorOp.
        // So MoveCursorOp(5, 0) targets col 5 (0-based) = col 6 (1-based).
        // Since encoder is at col 7, the cursor move to col 6 IS needed — emit \x1b[6H.
        $this->encoder->encode([
            new SetCellOp([Cell::new('中', null, null, 2)]),
            new RepeatRunOp('中', 2, 2),
        ]);

        $bytes = $this->encoder->encode([new MoveCursorOp(5, 0)]);

        // The move to (5, 0) = (6, 1-based) is emitted because the encoder is
        // at col 7 after the wide REP (col 3 + 2*2 = 7).  If width were ignored,
        // the encoder would be at col 5 and the move would be a no-op.
        // Note: row=0 → 1-based row=1, so full CUP form is \x1b[1;6H.
        $this->assertSame("\x1b[1;6H", $bytes);
    }
}
