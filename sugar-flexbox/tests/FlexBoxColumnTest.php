<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use SugarCraft\Flexbox\Flex\{Align, Direction, FlexBox, FlexItem};
use PHPUnit\Framework\TestCase;

/**
 * Focused tests for FlexBox column-direction rendering.
 *
 * Covers ratio-based height allocation, gap rendering, per-item style,
 * alignment behavior in column mode, and padding-to-allocated-height.
 */
final class FlexBoxColumnTest extends TestCase
{
    // ---- Column layout: line count ----

    public function testColumnRenderLineCountMatchesAllocatedHeight(): void
    {
        $box = FlexBox::column(
            FlexItem::new("line1\nline2"),
            FlexItem::new("lineA\nlineB\nlineC"),
        );

        // renderColumn clamps to totalHeight (10) and produces at most 10 lines.
        $output = $box->render(20, 10);
        $lines = \explode("\n", $output);

        $this->assertCount(10, $lines);
    }

    public function testColumnRendersEmptyStringWhenNoItems(): void
    {
        $box = FlexBox::column();
        $this->assertSame('', $box->render(80, 24));
    }

    // ---- Column layout: ratio-based height allocation ----

    public function testColumnAllocatesHeightByRatio(): void
    {
        // Two items with equal default ratios (1:1). With totalHeight=9 and
        // equal ratios, each item gets round(9 * 1/2) = 5 allocated lines.
        // "top" occupies indices 0-4; "bottom" starts at index 5.
        $box = FlexBox::column(
            FlexItem::new("top"),
            FlexItem::new("bottom"),
        );

        $output = $box->render(20, 9);
        $lines = \explode("\n", $output);

        // The output should have 9 lines total (height=9).
        $this->assertCount(9, $lines);
        // First line should contain "top".
        $this->assertStringContainsString('top', $lines[0]);
        // "bottom" appears after "top"'s allocated space (5 lines with equal ratio).
        $this->assertStringContainsString('bottom', $lines[5]);
    }

    public function testColumnWithBasisTakesFixedSpace(): void
    {
        // "fixed" takes its basis (3 lines). The remaining 5 free lines go to
        // the only ratio item in full — the sum contract:
        // basis items are excluded from the flex ratio pool, so nothing is
        // silently dropped. Old round-based math lost 2 of 8 lines here.
        // Total: 3 (fixed, incl padding) + 5 (flexible, incl padding) = 8 lines.
        $box = FlexBox::column(
            FlexItem::new('fixed')->withBasis(3),
            FlexItem::new('flexible')->withRatio(1),
        );

        $output = $box->render(20, 8);
        $lines = \explode("\n", $output);

        $this->assertCount(8, $lines);
        $this->assertStringContainsString('fixed', $output);
    }

    // ---- Column layout: gap ----

    public function testColumnWithGapRendersEmptyLinesBetweenItems(): void
    {
        $box = FlexBox::column(
            FlexItem::new('TOP'),
            FlexItem::new('BOTTOM'),
        )->withGap(2);

        $output = $box->render(20, 10);
        $lines = \explode("\n", $output);

        // Check that gap (empty lines) appear between items.
        $topIndex = null;
        $bottomIndex = null;
        foreach ($lines as $i => $line) {
            if (\str_contains($line, 'TOP')) {
                $topIndex = $i;
            }
            if (\str_contains($line, 'BOTTOM')) {
                $bottomIndex = $i;
            }
        }

        $this->assertNotNull($topIndex, 'TOP must appear in output');
        $this->assertNotNull($bottomIndex, 'BOTTOM must appear in output');
        // There should be at least 2 empty lines (the gap) between items.
        $this->assertGreaterThanOrEqual(2, $bottomIndex - $topIndex - 1);
    }

    // ---- Column layout: alignment ----

    public function testColumnAlignStartLeftAlignsContent(): void
    {
        $box = FlexBox::column(
            FlexItem::new('x'),
        )->withAlign(Align::Start);

        $output = $box->render(10, 5);
        // Each line in column mode should be padded to totalWidth (10).
        foreach (\explode("\n", $output) as $line) {
            $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testColumnAlignEndRightAlignsContent(): void
    {
        $box = FlexBox::column(
            FlexItem::new('x'),
        )->withAlign(Align::End);

        $output = $box->render(10, 5);
        // In Align::End, content should be right-aligned.
        foreach (\explode("\n", $output) as $line) {
            $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testColumnAlignStretchExpandsToFullWidth(): void
    {
        $box = FlexBox::column(
            FlexItem::new('x'),
        )->withAlign(Align::Stretch);

        $output = $box->render(10, 5);
        // Every line should fill the full width.
        foreach (\explode("\n", $output) as $line) {
            $this->assertSame(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testColumnAlignCenterCentersContent(): void
    {
        $box = FlexBox::column(
            FlexItem::new('x'),
        )->withAlign(Align::Center);

        $output = $box->render(10, 5);
        foreach (\explode("\n", $output) as $line) {
            $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    // ---- Column layout: alignment DISCRIMINATED (findings-011/074) ----

    /**
     * Column-mode alignment places each line inside the width the ITEM
     * MEASURES (Stretch: the container width), never inside the
     * container itself — so the placement only shows between lines of
     * DIFFERENT lengths within one item. These are the frames that
     * finally discriminate Start/Center/End (the old pins asserted a
     * width loop every align passed).
     */
    public function testColumnAlignPlacesWithinTheItemsMeasuredWidth(): void
    {
        $item = FlexItem::new("abc\nd");

        $start  = FlexBox::column($item)->withAlign(Align::Start)->render(10, 2);
        $center = FlexBox::column($item)->withAlign(Align::Center)->render(10, 2);
        $end    = FlexBox::column($item)->withAlign(Align::End)->render(10, 2);

        $this->assertSame("abc       \nd         ", $start);
        $this->assertSame("abc       \n d        ", $center);
        $this->assertSame("abc       \n  d       ", $end);
    }

    public function testColumnAlignIsInertForUniformWidthsByConstruction(): void
    {
        // The honest converse (finding-074): a single-width item answers
        // byte-identical frames to Start/Center/End — inert by
        // construction, NOW documented rather than silently promised.
        // Container-relative centring lives one layer up (pad the box).
        $uniform = FlexItem::new('ab');
        $start = FlexBox::column($uniform)->withAlign(Align::Start)->render(6, 1);
        $this->assertSame('ab    ', $start);
        $this->assertSame($start, FlexBox::column($uniform)->withAlign(Align::Center)->render(6, 1));
        $this->assertSame($start, FlexBox::column($uniform)->withAlign(Align::End)->render(6, 1));
        $this->assertSame($start, FlexBox::column($uniform)->withAlign(Align::Stretch)->render(6, 1));
    }

    public function testRowModeAlignPlacesWithinTheAllocatedWidth(): void
    {
        // Row mode DID discriminate all along — the flex allocation
        // gives each 1:1 item a 10-cell box for 2-cell content, and
        // every align case rides inside that box (the row-mode tests
        // that never existed per finding-011).
        $box = static fn (Align $a): string => FlexBox::row(
            FlexItem::new('ab'),
            FlexItem::new('cd'),
        )->withGap(0)->withAlign($a)->render(20, 1);

        $this->assertSame('ab        cd        ', $box(Align::Start));
        $this->assertSame('    ab        cd    ', $box(Align::Center));
        $this->assertSame('        ab        cd', $box(Align::End));
        $this->assertSame($box(Align::Start), $box(Align::Stretch), 'Stretch pads right like Start on one-liners');
    }

    // ---- Column layout: style ----

    public function testColumnItemWithStyleRendersStyledOutput(): void
    {
        $box = FlexBox::column(
            FlexItem::new('styled')->withStyle('31'), // red
        );

        $output = $box->render(20, 5);
        $this->assertStringContainsString("\x1b[31m", $output, 'Red SGR code must appear for styled item');
        $this->assertStringContainsString('styled', $output);
    }

    // ---- Column layout: truncation at totalHeight ----

    public function testColumnClampsOutputToTotalHeight(): void
    {
        $box = FlexBox::column(
            FlexItem::new("line1\nline2\nline3\nline4\nline5\nline6"),
        );

        $output = $box->render(20, 4);
        $lines = \explode("\n", $output);

        $this->assertCount(4, $lines, 'Output must be clamped to totalHeight');
    }

    // ---- Column layout: multi-line item ----

    public function testColumnMultiLineItemRendersAllLines(): void
    {
        $box = FlexBox::column(
            FlexItem::new("AAA\nBBB\nCCC"),
        );

        $output = $box->render(10, 5);
        $this->assertStringContainsString('AAA', $output);
        $this->assertStringContainsString('BBB', $output);
        $this->assertStringContainsString('CCC', $output);
    }

    // ---- Column + row interoperability ----

    public function testColumnDirectionRoundtrip(): void
    {
        $box = FlexBox::column(FlexItem::new('x'));
        $this->assertSame(Direction::Column, $box->direction);

        $row = $box->withDirection(Direction::Row);
        $this->assertSame(Direction::Row, $row->direction);
    }

    /**
     * Sum contract on the main axis vertical: equal
     * ratios must fill the requested height exactly — the old round-based
     * split of 10 cells across 3 ratio items rendered only 9 lines.
     */
    public function testColumnRatiosConsumeTheWholeHeight(): void
    {
        $box = FlexBox::column(
            FlexItem::new('A')->withRatio(1),
            FlexItem::new('B')->withRatio(1),
            FlexItem::new('C')->withRatio(1),
        );

        $lines = \explode("\n", $box->render(10, 10));
        $this->assertCount(10, $lines, 'round-robin remainder carry (equivalent sum contract) must use every row');
    }

    // ---- Column hardening (lib-fixes-1103) ----------------------------------

    public function testZeroRatioItemKeepsFloorInColumnMode(): void
    {
        $box = FlexBox::column(
            FlexItem::new('top')->withRatio(0),
            FlexItem::new('bottom')->withRatio(1),
        );

        $lines = \explode("\n", $box->render(10, 4));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('top', $lines[0]);
        $this->assertStringContainsString('bottom', $lines[1], 'the flex sibling must start at the item floor, not swallow rows');
    }

    public function testColumnGapOnlyBetweenItems(): void
    {
        $box = FlexBox::column(
            FlexItem::new('A')->withBasis(1),
            FlexItem::new('B')->withBasis(1),
        )->withGap(2);

        $lines = \explode("\n", $box->render(10, 5));
        // A(1) + gap(2) + B(1) = 4 lines. The old shape appended a trailing
        // gap row after the LAST item, spending a budget row on nothing.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('A', $lines[0]);
        $this->assertStringContainsString('B', $lines[3]);
        foreach ($lines as $line) {
            $this->assertSame(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testColumnTruncatesLinesToContainerWidth(): void
    {
        // Fit contract C3(a): a 20-cell line in a 10-wide box used to print
        // 20 cells (alignCell truncated only to the item's measured width and
        // padRight never truncates), collapsing page geometry downstream.
        $box = FlexBox::column(
            FlexItem::new('0123456789ABCDEFGHIJ'),
        )->withAlign(Align::Start);

        $lines = \explode("\n", $box->render(10, 1));
        $this->assertCount(1, $lines);
        $this->assertSame(10, \SugarCraft\Core\Util\Width::string($lines[0]));
        $this->assertStringContainsString('01234', $lines[0]);
        $this->assertStringNotContainsString('KLMNOPQRST', $lines[0]);
    }

    public function testColumnTruncatesWideGlyphLinesToContainerWidth(): void
    {
        // The same contract in cells, not bytes: 8 CJK glyphs = 16 cells.
        $box = FlexBox::column(
            FlexItem::new('日本語のテキスト'),
        )->withAlign(Align::Start);

        $lines = \explode("\n", $box->render(5, 1));
        $this->assertCount(1, $lines);
        $this->assertLessThanOrEqual(5, \SugarCraft\Core\Util\Width::string($lines[0]), 'cell-aware truncate must never leave a 6-cell remnant in a 5-wide box');
    }

    public function testColumnNeverPrintsMoreLinesThanAllocatedHeight(): void
    {
        // Fit contract C3(c): the old foreach emitted EVERY content line and
        // only ever padded up, so a five-line item with 2 reserved rows stole
        // the viewport and the following sibling vanished from the window.
        $box = FlexBox::column(
            FlexItem::new("a1\na2\na3\na4\na5")->withBasis(2),
            FlexItem::new('bee')->withBasis(2),
        )->withAlign(Align::Start);

        $lines = \explode("\n", $box->render(5, 4));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('a1', $lines[0]);
        $this->assertStringContainsString('a2', $lines[1]);
        $this->assertStringContainsString('bee', $lines[2], 'the second item must keep its reserved rows');
        $this->assertStringNotContainsString('a3', \implode("\n", $lines));
        $this->assertStringNotContainsString('a5', \implode("\n", $lines));
    }
}
