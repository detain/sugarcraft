<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use SugarCraft\Stickers\Flex\{Align, Direction, FlexBox, FlexItem};
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
        // "fixed" takes its basis (3 lines). Remaining 5 lines are split by
        // ratio (1:1), giving flexible round(5 * 1/2) = 3 lines.
        // Total: 3 (fixed, incl padding) + 3 (flexible, incl padding) = 6 lines.
        $box = FlexBox::column(
            FlexItem::new('fixed')->withBasis(3),
            FlexItem::new('flexible')->withRatio(1),
        );

        $output = $box->render(20, 8);
        $lines = \explode("\n", $output);

        $this->assertCount(6, $lines);
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
}
