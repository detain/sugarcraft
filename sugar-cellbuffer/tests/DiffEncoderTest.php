<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;
use SugarCraft\CellBuffer\Diff\EraseRunOp;
use SugarCraft\CellBuffer\Diff\MoveCursorOp;
use SugarCraft\CellBuffer\Diff\RepeatRunOp;
use SugarCraft\CellBuffer\Diff\SetCellOp;
use SugarCraft\CellBuffer\Diff\SetHyperlinkOp;
use SugarCraft\CellBuffer\Diff\SetStyleOp;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Style;

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

        $this->assertSame("\x1b[1;1H", $bytes);
    }

    public function testEncodeMoveCursorOp(): void
    {
        $ops = [new MoveCursorOp(4, 2)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H\x1b[3;5H", $bytes);
    }

    public function testEncodeMoveCursorSamePositionIsNoOp(): void
    {
        $ops = [
            new MoveCursorOp(0, 0),
            new MoveCursorOp(0, 0),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H", $bytes);
    }

    public function testEncodeMoveCursorAlreadyAtPositionFromDifferentCursor(): void
    {
        // Each encode() call resets state, so subsequent calls are independent.
        // Here the second call starts from (1,1) and moves to (3,2).
        $encoder = new DiffEncoder();
        $encoder->encode([new MoveCursorOp(3, 2)]);
        $bytes = $encoder->encode([new MoveCursorOp(3, 2)]);

        $this->assertSame("\x1b[1;1H\x1b[3;4H", $bytes);
    }

    public function testEncodeRepeatRunOp(): void
    {
        $ops = [
            new SetCellOp([Cell::new('A')]),
            new RepeatRunOp('A', 3),
        ];
        $bytes = $this->encoder->encode($ops);

        // A-02: count 3 of a 1-byte rune is below the byte break-even
        // ("\x1b[3b" costs 4, "AAA" costs 3) — the run stays literal.
        $this->assertSame("\x1b[1;1H" . "AAAA", $bytes);
    }

    public function testEncodeRepeatRunOpZeroCount(): void
    {
        $ops = [
            new SetCellOp([Cell::new('X')]),
            new RepeatRunOp('X', 0),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H" . "X", $bytes);
    }

    public function testEncodeRepeatRunFallbackWhenRuneMismatch(): void
    {
        $encoder = new DiffEncoder();
        $encoder->encode([new SetCellOp([Cell::new('A')])]);
        $bytes = $encoder->encode([new RepeatRunOp('X', 2)]);

        // A-02: count 2 of a 1-byte rune is sub-threshold, so the defensive
        // shape lands on the literal arm — which paints the op's OWN rune,
        // strictly more faithful than REP (REP would have repeated the stale
        // 'A' sitting under the cursor while only relabelling bookkeeping).
        $this->assertSame("\x1b[1;1H" . "XX", $bytes);
    }

    public function testEncodeRepeatRunEmitsRepSequence(): void
    {
        $ops = [
            new SetCellOp([Cell::new('A')]),
            new RepeatRunOp('A', 5),
        ];
        $bytes = $this->encoder->encode($ops);

        // A-02: count 5 is the first strict win for a 1-byte rune
        // ("\x1b[5b" costs 4 bytes, "AAAAA" costs 5) — REP is emitted.
        $this->assertSame("\x1b[1;1H" . "A\x1b[5b", $bytes);
    }

    public function testEncodeSetStyleOp(): void
    {
        $ops = [new SetStyleOp(Style::bold())];
        $bytes = $this->encoder->encode($ops);

        // B1 (lane A3a): a frame that ENDS styled is closed with an SGR
        // reset so the next raw write cannot inherit the ghost colour.
        $this->assertSame("\x1b[1;1H\x1b[0;1m\x1b[0m", $bytes);
    }

    public function testEncodeSetStyleOpNullAgainstTheDefaultPenIsSkipped(): void
    {
        // sugar-cellbuffer-009 disclosure: the old stream carried a redundant
        // reset for a null-vs-null (no-transition) op. The skip the SetStyleOp
        // docblock promised is now real, and the terminal state is identical:
        // the pen was already default.
        $ops = [new SetStyleOp(null)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H", $bytes);
    }

    public function testADoubledSetStyleOpEmitsTheSgrOnce(): void
    {
        $ops = [new SetStyleOp(Style::bold()), new SetStyleOp(Style::bold())];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H\x1b[0;1m\x1b[0m", $bytes);
    }

    public function testASetStyleOpEqualByValueToTheCellJustWrittenEmitsNothing(): void
    {
        // Value equality, not identity: the pen arrives from a different
        // instance and must still be recognised as unchanged.
        $bold = Style::new(null, null, Style::ATTR_BOLD);
        $ops = [
            new SetCellOp([Cell::new('X', Style::bold())]),
            new SetStyleOp($bold),
            new SetCellOp([Cell::new('Y', $bold)]),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H\x1b[0;1mXY\x1b[0m", $bytes);
    }

    public function testARealTransitionStillRidesAfterADuplicate(): void
    {
        $ops = [
            new SetStyleOp(Style::bold()),
            new SetStyleOp(Style::bold()),
            new SetStyleOp(Style::reverse()),
        ];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H\x1b[0;1m\x1b[0;7m\x1b[0m", $bytes);
    }

    public function testEncodeSetHyperlinkOpOpen(): void
    {
        $link = Hyperlink::new('https://example.com');
        $ops = [new SetHyperlinkOp($link)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H\x1b]8;;https://example.com\x1b\\\x1b]8;;\x1b\\", $bytes);
    }

    public function testEncodeSetHyperlinkOpOpenWithId(): void
    {
        $link = Hyperlink::new('https://example.com', 'myid');
        $ops = [new SetHyperlinkOp($link)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H\x1b]8;id=myid;https://example.com\x1b\\\x1b]8;;\x1b\\", $bytes);
    }

    public function testEncodeSetHyperlinkOpCloseWithNoOpenLinkIsNoOp(): void
    {
        $ops = [new SetHyperlinkOp(null)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H", $bytes);
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

        $this->assertSame("\x1b[1;1H\x1b]8;;https://example.com\x1b\\\x1b]8;;\x1b\\X", $bytes);
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

        // Trailing reset per B1 — the stream must end at default rendition.
        $this->assertSame("\x1b[1;1H\x1b[0;1mAB\x1b[0m", $bytes);
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

        // Trailing reset per B1 — italic was live at end of stream.
        $this->assertSame("\x1b[1;1H\x1b[0;1mB\x1b[0;3mI\x1b[0m", $bytes);
    }

    public function testEncodeWideCharAdvancesCursorByWidth2(): void
    {
        $cell = Cell::new('中', null, null, 2);
        $ops = [new SetCellOp([$cell])];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H" . '中', $bytes);
    }

    public function testEncodeCompositeStyle(): void
    {
        $style = Style::new(0x123456, 0xABCDEF, Style::ATTR_BOLD | Style::ATTR_UNDERLINE);
        $cell = Cell::new('S', $style);
        $ops = [new SetCellOp([$cell])];
        $bytes = $this->encoder->encode($ops);

        // Trailing reset per B1 — composite style was live at end of stream.
        $this->assertSame("\x1b[1;1H\x1b[0;38;2;18;52;86;48;2;171;205;239;1;4mS\x1b[0m", $bytes);
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
        $this->assertSame("\x1b[1;1H\x1b[1;6H", $bytes);
    }

    public function testEncodeSetHyperlinkCloseWhenLinkAlreadyClosed(): void
    {
        // SetHyperlinkOp(null) when currentLinkUrl is already null should return empty
        $ops = [new SetHyperlinkOp(null)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H", $bytes);
    }

    public function testEncodeOpenThenCloseHyperlink(): void
    {
        $link = Hyperlink::new('https://example.com');
        $ops = [
            new SetHyperlinkOp($link),
            new SetCellOp([Cell::new('L', null, $link)]),
            new SetHyperlinkOp(null),
        ];
        $bytes = $this->encoder->encode($ops);

        // OSC 8 open, rune, OSC 8 close
        $this->assertStringContainsString('https://example.com', $bytes);
        $this->assertStringContainsString("\x1b]8;;\x1b\\", $bytes);
        $this->assertStringContainsString('L', $bytes);
    }

    public function testEncodeMoveCursorZeroPositionIsNoOp(): void
    {
        // Initial cursor is (1,1) 1-based = (0,0) 0-based.
        // MoveCursorOp(0,0) is a no-op since we're already there.
        $ops = [new MoveCursorOp(0, 0)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H", $bytes);
    }

    public function testEncodeEraseRunPositiveCount(): void
    {
        $ops = [new EraseRunOp(3)];
        $bytes = $this->encoder->encode($ops);

        $this->assertSame("\x1b[1;1H\x1b[3X", $bytes);
    }

    public function testEncodeEncodeStateIsResetBetweenCalls(): void
    {
        // First encode sets cursor to (6,6) 1-based and applies bold style
        $this->encoder->encode([
            new MoveCursorOp(5, 5),
            new SetStyleOp(Style::bold()),
        ]);

        // Second encode should start fresh with cursor at (1,1) 1-based
        // MoveCursorOp(0,0) is a no-op when starting from reset state
        $bytes = $this->encoder->encode([new MoveCursorOp(0, 0)]);
        $this->assertSame("\x1b[1;1H", $bytes);

        // But moving to a different position should emit CUP
        $bytes2 = $this->encoder->encode([new MoveCursorOp(4, 3)]);
        $this->assertSame("\x1b[1;1H\x1b[4;5H", $bytes2); // 5,4 0-based = 4,5 1-based
    }

    public function testEncodeCompositeOpsWithHyperlinkTransition(): void
    {
        $link1 = Hyperlink::new('https://a.com');
        $link2 = Hyperlink::new('https://b.com');

        $ops = [
            new SetCellOp([Cell::new('A', null, $link1)]),
            new SetCellOp([Cell::new('B', null, $link2)]),
        ];

        $bytes = $this->encoder->encode($ops);

        // Should contain both URLs and close before switching
        $this->assertStringContainsString('https://a.com', $bytes);
        $this->assertStringContainsString('https://b.com', $bytes);
        $this->assertStringContainsString("\x1b]8;;\x1b\\", $bytes);
    }

    // ─── B1 (lane A3a) — trailing SGR reset on the delta wire ────────────

    public function testStyledTailFrameEndsWithAnSgrReset(): void
    {
        // Probe shape from the p8a audit: blank previous frame, the current
        // one paints a single red 'X' at column 2. Before the fix encode()
        // returned bytes that left the REAL terminal styled — the comment
        // above the hyperlink close promised the reset but never emitted it.
        $bytes = $this->encoder->encode([
            new MoveCursorOp(2, 0),
            new SetCellOp([Cell::new('X', Style::new(0xFF0000))]),
        ]);

        $this->assertSame("\x1b[1;1H\x1b[1;3H\x1b[0;38;2;255;0;0mX\x1b[0m", $bytes);
    }

    public function testUnstyledFrameCarriesNoTrailingReset(): void
    {
        // The reset is CONDITIONAL (Buffer::toAnsi() parity): a stream that
        // never activated a style stays byte-identical to the rune alone.
        $bytes = $this->encoder->encode([new SetCellOp([Cell::new('x')])]);

        $this->assertSame("\x1b[1;1H" . 'x', $bytes);
    }

    public function testExplicitStyleClearIsNotFollowedByASecondReset(): void
    {
        $bytes = $this->encoder->encode([
            new SetCellOp([Cell::new('X', Style::bold())]),
            new SetStyleOp(null),
        ]);

        $this->assertSame("\x1b[1;1H\x1b[0;1mX\x1b[0m", $bytes);
    }

    public function testConsumerShapedConcatenationDoesNotBleedStyleIntoTheNextFrame(): void
    {
        // sugar-overlay RenderSession and sugar-dashboard Chart return encode()
        // verbatim; a following delta frame that clears the red cell opens
        // with ECH at whatever rendition the terminal is in. With the tail
        // reset the combined wire is red-X, RESET, ECH; without it the ECH
        // erases while the terminal is still red and the "cleared" cell
        // ghosts red.
        $frame = $this->encoder->encode([
            new MoveCursorOp(2, 0),
            new SetCellOp([Cell::new('X', Style::new(0xFF0000))]),
        ]);
        $clear = $this->encoder->encode([new EraseRunOp(1)]);

        $this->assertSame(
            "\x1b[1;1H\x1b[1;3H\x1b[0;38;2;255;0;0mX\x1b[0m"
            . "\x1b[1;1H\x1b[1X",
            $frame . $clear,
            'the erase frame must start at default rendition',
        );
    }

    public function testANegativeCursorClampsToTheGridEdgeOnTheWire(): void
    {
        // Render path is fail-soft (state mutation refuses negatives in
        // Buffer::applyDiff); the encoder clamps instead of emitting
        // ESC[-1;..H, which no terminal can parse. The clamp lands on the
        // home position, so step away first to observe the emitted CUP.
        $bytes = $this->encoder->encode([new MoveCursorOp(4, 2), new MoveCursorOp(-3, -2)]);

        $this->assertSame("\x1b[1;1H\x1b[3;5H\x1b[1;1H", $bytes);
    }

    public function testTheEmittersSpellTheHyperlinkOpenThroughOneHelper(): void
    {
        // Reverse-parity: the OSC 8 open frame is spelled in exactly one
        // place — Hyperlink::toAnsi() — per spec grammar
        // `OSC 8 ; params ; URI ST`. Two historical bugs redden these pins:
        // the bare ';<id>;' token (ignored by conforming terminals) and the
        // single-semicolon id-less slide, which lands the URL in params,
        // leaves the URI field absent, and reads as a CLOSE to this fleet's
        // own sugar-ansi-parser decoder.
        $withId = Hyperlink::new('https://example.com', 'myid');
        $noId = Hyperlink::new('https://example.com');

        $this->assertSame("\x1b]8;id=myid;https://example.com\x1b\\", $withId->toAnsi());
        $this->assertSame("\x1b]8;;https://example.com\x1b\\", $noId->toAnsi());

        // Fleet parity: byte-identical to sugar-core's canonical emitter.
        $core = \SugarCraft\Core\Util\Ansi::class;
        $this->assertSame($core::hyperlinkOpen($withId->url(), $withId->id()), $withId->toAnsi());
        $this->assertSame($core::hyperlinkOpen($noId->url()), $noId->toAnsi());

        // Emitter agreement: repaint path (toAnsi) and delta path (encode)
        // both ship the helper's exact bytes for either shape.
        foreach ([$withId, $noId] as $link) {
            $painted = Buffer::new(1, 1)
                ->withCellAt(0, 0, Cell::new('L', null, $link))
                ->toAnsi();
            $delta = $this->encoder->encode([
                new SetHyperlinkOp($link),
                new SetCellOp([Cell::new('L', null, $link)]),
            ]);
            $this->assertStringContainsString($link->toAnsi(), $painted);
            $this->assertStringStartsWith("\x1b[1;1H" . $link->toAnsi(), $delta);
        }

        // Neither emitter hand-rolls OSC 8 frames any more — the literals
        // live only in the helper and the CLOSE const.
        foreach (
            [
                __DIR__ . '/../src/Buffer.php',
                __DIR__ . '/../src/Diff/DiffEncoder.php',
            ] as $file
        ) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('\x1b]8', $source, "an emitter re-spells OSC 8 bytes in {$file}");
        }
    }

    public function testEveryFrameOpensWithTheHomeAnchorIncludingAnOriginOnlyPaint(): void
    {
        // TUI-01 cure: the frame is self-anchored. Before the prologue a
        // delta whose only change sits at (0,0) carried ZERO positioning
        // bytes — sugar-overlay/sugar-dashboard return encode() verbatim to
        // a live terminal, so the paint landed wherever the previous frame
        // left the cursor (whole-frame mis-paint class). The unconditional
        // \x1b[1;1H matches the contract the README always promised.
        $bytes = $this->encoder->encode([new SetCellOp([Cell::new('X')])]);

        $this->assertSame("\x1b[1;1HX", $bytes);
    }

    public function testThePrologueRidesEvenOnAnEmptyOpStream(): void
    {
        // Unconditional means unconditional: an empty frame still anchors
        // home, so a consumer that skips painting but flushes the stream
        // cannot leave the terminal mid-screen for the next writer.
        $this->assertSame("\x1b[1;1H", $this->encoder->encode([]));
    }

    public function testAnUnknownDiffOpIsRefusedInsteadOfVanishing(): void
    {
        // encodeOp's instanceof cascade used to end in `return ''`, so a
        // seventh op type — or a hand-built stream carrying one — dropped
        // its bytes silently. Fail-fast names the class (sugar-cellbuffer-025).
        $op = new Support\PhantomOp();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unencodable diff op ' . Support\PhantomOp::class);

        $this->encoder->encode([$op]);
    }

    public function testTheKnownOpTypesAllRemainEncodable(): void
    {
        // The throw is the cascade's ONLY tail: every shipped op still
        // encodes, so the refusal cannot silently swallow a real frame.
        $bytes = $this->encoder->encode([
            new MoveCursorOp(2, 1),
            new SetStyleOp(Style::bold()),
            new SetCellOp([Cell::new('X')]),
            new RepeatRunOp('X', 9),
            new EraseRunOp(2),
            new SetHyperlinkOp(new Hyperlink('https://example.com', 'pinned')),
        ]);

        $this->assertStringContainsString("\x1b[2;3H", $bytes);
        $this->assertStringContainsString('X', $bytes);
        $this->assertStringContainsString("\x1b[9b", $bytes);
        $this->assertStringContainsString("\x1b[2X", $bytes);
        $this->assertStringContainsString("\x1b]8;id=pinned;https://example.com\x1b\\", $bytes);
    }

    // ─── One-step diff encoding (sugar-cellbuffer-019) ──────────────────

    public function testDiffToAnsiIsByteIdenticalToTheHandWiredPair(): void
    {
        $prev = Buffer::new(6, 2);
        $curr = $prev
            ->withCellAt(1, 0, Cell::new('X', Style::fromSgr('1;31'), new Hyperlink('https://example.com')))
            ->withCellAt(4, 1, Cell::new('Y', Style::fromSgr('4')));

        $handWired = (new DiffEncoder())->encode($curr->diff($prev));

        self::assertSame($handWired, $curr->diffToAnsi($prev));
        // The canonical flow is still real bytes, not an empty stub.
        self::assertStringStartsWith("\x1b[1;1H", $handWired);
        self::assertStringContainsString('X', $handWired);
    }

    public function testDiffToAnsiOfAnUnchangedFrameIsTheBarePrologue(): void
    {
        $buf = Buffer::new(3, 1);

        self::assertSame("\x1b[1;1H", $buf->diffToAnsi($buf));
    }
}
