<?php

declare(strict_types=1);

/*
 * Unit pins for MarkedFrame — the cell-aware measure/take/drop/isolation
 * walker that keeps sugar-overlay's width math in VISIBLE columns.
 *
 * The C1 defect family (round lib-fixes-1103): zone marker envelopes
 * (U+E000 id U+E001) and OSC-8 link bodies were charged as cells wherever
 * Width::string() measured lines, so anchoring and clipping desynced from
 * what paintRow actually places. These pins hold the corrected law:
 * envelopes cost zero, truncation keeps zones balanced, dropped heads
 * replay their open envelope, and style state at a splice boundary is
 * neutered when it would bleed into the overlay.
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Overlay\MarkedFrame;

final class MarkedFrameTest extends TestCase
{
    private const OPEN = "\xEE\x80\x80";
    private const CLOSE = "\xEE\x80\x81";

    private function envelope(string $id): string
    {
        return self::OPEN . $id . self::CLOSE;
    }

    // ------------------------------------------------------------------
    # measure
    // ------------------------------------------------------------------

    public function testPlainTextMeasuresCells(): void
    {
        $this->assertSame(3, MarkedFrame::measure('abc'));
        $this->assertSame(2, MarkedFrame::measure('a '));
    }

    public function testWideClustersCountTheirColumns(): void
    {
        $this->assertSame(4, MarkedFrame::measure('日本'));
        $this->assertSame(3, MarkedFrame::measure('a😀'));
    }

    public function testZoneEnvelopesCostNothing(): void
    {
        $marked = $this->envelope('btn') . '[OK]' . $this->envelope('/btn');
        $this->assertSame(4, MarkedFrame::measure($marked));
    }

    public function testSgrAndOsc8CostNothing(): void
    {
        $line = "\e[31m\e]8;;http://example.com\x07X\e]8;;\x07\e[0m";
        $this->assertSame(1, MarkedFrame::measure($line));
    }

    public function testUnterminatedOscCostsNothing(): void
    {
        // An OSC swallowed by end-of-line paints nothing visible (paintRow
        // consumes it); measure must agree, C1 backdrop-hole family.
        $this->assertSame(1, MarkedFrame::measure("A\e]0;title"));
    }

    public function testUnmatchedZoneOpenSwallowsTailAndCostsNothing(): void
    {
        // A stray OPEN with no CLOSE ahead is the degenerate case: it (and
        // everything after it) is one zone-open envelope, zero columns —
        // parity with paintRow's strcspn walk that swallows the tail.
        $this->assertSame(1, MarkedFrame::measure('A' . self::OPEN . 'oops'));
    }

    public function testStrayCloseCostsNothing(): void
    {
        $this->assertSame(1, MarkedFrame::measure('A' . self::CLOSE));
    }

    /**
     * SO-04 (sugar-overlay-029) parity pin: the zero-allocation byte walker
     * behind measure() must agree with the full tokenizer (segments()) on
     * every corpus line — both walk the same arms, so a control-flow change
     * on either side reddens here instead of silently re-measuring.
     *
     * @dataProvider measureParityCorpus
     */
    public function testMeasureWalkerAgreesWithSegments(string $line): void
    {
        $sum = 0;
        foreach (MarkedFrame::segments($line) as $seg) {
            $sum += $seg['width'];
        }

        $this->assertSame(
            $sum,
            MarkedFrame::measure($line),
            'walker/tokenizer width parity for [' . addcslashes($line, "\0..\37\177..\377") . ']',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function measureParityCorpus(): array
    {
        return [
            'empty' => [''],
            'ascii' => ['abc def'],
            'wide-cjk' => ['日本語テスト'],
            'zwj-family' => ['👨‍👩‍👧x'],
            'skin-tone' => ['👍🏿x'],
            'flag' => ['🇺🇸'],
            'combining-vs' => ["♥\xEF\xB8\x80"],
            'zone-balanced' => ["a\xEE\x80\x80id\xEE\x80\x81b"],
            'zone-open-unclosed' => ["a\xEE\x80\x80tail"],
            'zone-close-slash' => ["/open\xEE\x80\x81x"],
            'zone-stray-close' => ["x\xEE\x80\x81y"],
            'zone-sentinel-truncated' => ["\xEE\x80"],
            'sgr-simple' => ["\e[31mred\e[0m"],
            'sgr-rgb' => ["\e[38;2;1;2;3mpen\e[m"],
            'csi-unterminated-eol' => ["abc\e[38"],
            'osc8-st-pair' => ["\e]8;;http://x\e\\link\e]8;;\e\\"],
            'osc8-bel' => ["\e]8;;u\x07bel-terminated"],
            'osc8-bare-esc-reread' => ["\e]8;;bare-esc\x1b[0mafter"],
            'lone-esc-eol' => ["text\e"],
            'mixed-hostile' => ["\e[31m\xEE\x80\x80z語\xEE\x80\x81\e]8;;u\x07👍🏿✕"],
        ];
    }

    // ------------------------------------------------------------------
    # take
    // ------------------------------------------------------------------

    public function testTakePlainPrefix(): void
    {
        $this->assertSame('AB', MarkedFrame::take('ABCD', 2));
    }

    public function testTakeZeroOrNegativeYieldsEmpty(): void
    {
        $this->assertSame('', MarkedFrame::take('AB', 0));
        $this->assertSame('', MarkedFrame::take('AB', -3));
    }

    public function testTakeCutInsideZoneSynthesizesCloser(): void
    {
        $line = $this->envelope('btn') . 'CLICK';
        // The open envelope rides the truncated row (the zone stays live on
        // the clipped line); the closer is synthesized at the cut.
        $this->assertSame($this->envelope('btn') . 'CLI' . $this->envelope('/btn'), MarkedFrame::take($line, 3));
        $this->assertSame(3, MarkedFrame::measure(MarkedFrame::take($line, 3)));
    }

    public function testTakeKeepsRealCloserInsteadOfSynthesizing(): void
    {
        $line = $this->envelope('z') . 'AB' . $this->envelope('/z');
        $out = MarkedFrame::take($line, 1);
        // Exactly two envelopes (open + the REAL close) survive; the
        // end-synth must not add a third.
        $this->assertSame(2, \substr_count($out, self::OPEN));
        $this->assertStringContainsString($this->envelope('/z'), $out);
        $this->assertSame(1, MarkedFrame::measure($out));
    }

    public function testTakeHarvestsSgrPastBudget(): void
    {
        $line = "\e[1m" . $this->envelope('z') . 'AB';
        $out = MarkedFrame::take($line, 1);
        $this->assertSame("\e[1m" . $this->envelope('z') . 'A' . $this->envelope('/z'), $out);
    }

    public function testTakeSynthesizesLinkCloserWhenCutInsideLink(): void
    {
        $line = "\e]8;;http://x\x07AB";
        $this->assertSame("\e]8;;http://x\x07A" . "\x1b]8;;\x07", MarkedFrame::take($line, 1));
    }

    public function testTakeDropsPostBudgetLinkOpener(): void
    {
        // A link opening past the budget must not ride the truncated row —
        // splicing an orphan opener onto a backdrop would extend it into
        // following cells (M3 OSC-8 integrity family).
        $line = 'A' . "\e]8;;http://x\x07B";
        $this->assertSame('A', MarkedFrame::take($line, 1));
    }

    public function testTakeKeepsRealLinkCloserPastBudget(): void
    {
        $line = "\e]8;;http://x\x07" . 'A' . "\e]8;;\x07" . 'B';
        $this->assertSame("\e]8;;http://x\x07A\e]8;;\x07", MarkedFrame::take($line, 1));
    }

    public function testTakeNeverSplitsStraddlingWide(): void
    {
        $line = 'A日B';
        // Budget 2: 'A' then '日' (width 2) straddles — everything past it
        // is cut, matching Width::truncateAnsi's stop law.
        $this->assertSame('A', MarkedFrame::take($line, 2));
    }

    public function testTakeDropsZoneOpeningWhollyPastBudget(): void
    {
        $line = 'AB' . $this->envelope('gone') . 'CD';
        $this->assertSame('AB', MarkedFrame::take($line, 2));
    }

    // ------------------------------------------------------------------
    # drop
    // ------------------------------------------------------------------

    public function testDropExactBoundary(): void
    {
        $this->assertSame('CD', MarkedFrame::drop('ABCD', 2));
    }

    public function testDropZeroReturnsLine(): void
    {
        $this->assertSame('AB', MarkedFrame::drop('AB', 0));
    }

    public function testDropOverrunReturnsEmpty(): void
    {
        $this->assertSame('', MarkedFrame::drop('AB', 5));
    }

    public function testDropInsideZoneReplaysOpenEnvelope(): void
    {
        $line = $this->envelope('z') . 'HELLO';
        $this->assertSame($this->envelope('z') . 'LLO', MarkedFrame::drop($line, 2));
    }

    public function testDropKeepsZoneWhenTailStillHasContent(): void
    {
        $line = $this->envelope('z') . 'ABCD' . $this->envelope('/z');
        $this->assertSame($this->envelope('z') . 'CD' . $this->envelope('/z'), MarkedFrame::drop($line, 2));
    }

    public function testDropRemovesOrphanCloserWhenZoneFullyDropped(): void
    {
        $line = $this->envelope('z') . 'AB' . $this->envelope('/z') . 'CD';
        // Dropping all of 'AB' leaves the close envelope orphaned — it must
        // not ride the tail as a stray (scanner would flag it).
        $this->assertSame('CD', MarkedFrame::drop($line, 2));
    }

    public function testDropConsumesStraddlingWideWhole(): void
    {
        // '日' occupies columns 1-2; a drop of 1 can't expose half of it —
        // the cluster is consumed whole (Width::dropAnsi parity).
        $this->assertSame('A', MarkedFrame::drop('日A', 1));
    }

    public function testDropReplaysHeadEscapes(): void
    {
        $line = "\e[1mAB";
        $this->assertSame("\e[1mB", MarkedFrame::drop($line, 1));
    }

    public function testDropReplaysHeadLinkOpener(): void
    {
        $line = "\e]8;;http://x\x07AB";
        $this->assertSame("\e]8;;http://x\x07B", MarkedFrame::drop($line, 1));
    }

    public function testDropTakesRoundTripPreservesBalance(): void
    {
        $line = $this->envelope('z') . 'HELLO' . $this->envelope('/z');
        $head = MarkedFrame::take($line, 2);
        $tail = MarkedFrame::drop($line, 2);
        // Both halves are individually balanced: measure adds back up.
        $this->assertSame(MarkedFrame::measure($line), MarkedFrame::measure($head) + MarkedFrame::measure($tail));
    }

    // ------------------------------------------------------------------
    # isolation
    // ------------------------------------------------------------------

    public function testIsolationNeutersBleedingForegroundPen(): void
    {
        $this->assertSame("\e[39m\e[49m", MarkedFrame::isolation("\e[31mA"));
    }

    public function testIsolationNeutersBleedingBackgroundPen(): void
    {
        $this->assertSame("\e[39m\e[49m", MarkedFrame::isolation("\e[41mB"));
    }

    public function testIsolationReadsExtendedColorLegs(): void
    {
        $this->assertSame("\e[39m\e[49m", MarkedFrame::isolation("\e[38;2;1;2;3mC"));
        // A reset-49 after a 38 leg leaves only fg set: still isolates.
        $this->assertSame("\e[39m\e[49m", MarkedFrame::isolation("\e[38;5;1m\e[49mC"));
        // 38 leg + matching 49 + 39 → both back to default → nothing to add.
        $this->assertSame('', MarkedFrame::isolation("\e[38;5;1m\e[39m\e[49mC"));
    }

    public function testIsolationStaysQuietOnNeutralOrEmptyStyle(): void
    {
        $this->assertSame('', MarkedFrame::isolation("\e[0mA"));
        $this->assertSame('', MarkedFrame::isolation("\e[1mA"));
        $this->assertSame('', MarkedFrame::isolation("\e[39m\e[49mA"));
        $this->assertSame('', MarkedFrame::isolation('plain'));
        $this->assertSame('', MarkedFrame::isolation(''));
        // A link alone is not a pen.
        $this->assertSame('', MarkedFrame::isolation("\e]8;;http://x\x07A"));
    }

    public function testTakeNeverSynthesizesTheClosingHalfOfAnEmptyIdClaim(): void
    {
        // SO-24 (sugar-overlay-035): an unmatched forged zone-open
        // tokenizes to id '' — sentinel garbage, not a zone. The synth at
        // the take-leg used to complete that claim into a full empty-id
        // envelope (OPEN + "/" + CLOSE) riding the wire as a zone-shaped
        // claim. The guard keeps the raw garbage verbatim and mints
        // nothing; a MATCHED zone left open at the cut still gets its
        // synth close (second leg below).
        $close = "\xEE\x80\x81";

        $clipped = MarkedFrame::take('Y' . self::OPEN . 'QQ', 2);
        $this->assertSame('Y' . self::OPEN . 'QQ', $clipped, 'the dangling open rides raw; no closing half is minted');
        $this->assertStringNotContainsString($close, $clipped);

        // Wire format: an open claim is OPEN+id+CLOSE and a real close is
        // OPEN+'/id'+CLOSE — this row leaves zone 'z' open across the cut.
        $matched = MarkedFrame::take('Y' . self::OPEN . 'z' . $close . 'ZA', 2);
        $this->assertStringEndsWith(self::OPEN . '/z' . $close, $matched, 'a real zone left open at the cut keeps its synth close');
    }

    public function testDropReplaySkipsEmptyIdZoneClaims(): void
    {
        // SO-24 drop-side belt (sugar-overlay-035): the head-replay guard
        // refuses an empty-id $zone the same way; segments() itself can
        // currently only produce this shape on the take leg, so the belt
        // is pinned at the public segment-input door.
        $forged = [
            ['kind' => 'zone-open',  'bytes' => self::OPEN . 'head', 'width' => 0, 'id' => ''],
            ['kind' => 'text',       'bytes' => 'B',                'width' => 1, 'id' => ''],
            ['kind' => 'text',       'bytes' => 'C',                'width' => 1, 'id' => ''],
        ];

        [$tail, $width] = MarkedFrame::dropSegments($forged, 1);

        $this->assertStringNotContainsString("\xEE\x80\x81", $tail, 'no closing half is replayed for an empty-id claim');
        $this->assertStringNotContainsString(self::OPEN, $tail, 'nor is the opening half re-minted');
        $this->assertSame(1, $width);
    }
}
