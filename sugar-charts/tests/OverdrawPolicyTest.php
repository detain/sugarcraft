<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Canvas\Graph;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Core\Util\Ansi;

/**
 * CH-32 (sugar-charts-103): the overdraw / collision policy table, one row
 * per string-placement surface, each pinned on a fixture:
 *
 *  | surface                         | policy                                   |
 *  |---------------------------------|------------------------------------------|
 *  | x labels, overlapping/duplicate | first wins, later skipped + disclosed    |
 *  | x labels, right-anchored last   | last displaces the label it collides with |
 *  | y labels wider than the gutter  | end-truncated to the gutter + disclosed  |
 *  | Scatter shared cell             | Scatter::OVERLAP_RUNE (merge glyph)      |
 */
final class OverdrawPolicyTest extends TestCase
{
    /** @return array{0:list<string>,1:list<string>} [rows, notes] */
    private static function labels(int $w, int $xOrigin, int $xLen, array $x, array $y): array
    {
        $c = new Canvas($w, 6);
        $notes = Graph::drawXYAxisLabel($c, $xOrigin, 4, $xLen, 4, $x, $y);
        return [explode("\n", Ansi::strip($c->view())), $notes];
    }

    public function testXLabelsCleanFixtureDisclosesNothing(): void
    {
        [$rows, $notes] = self::labels(30, 3, 24, ['a', 'b', 'c'], []);
        $this->assertSame([], $notes);
        $this->assertStringContainsString('a', $rows[5]);
        $this->assertStringContainsString('b', $rows[5]);
        $this->assertStringContainsString('c', $rows[5]);
    }

    public function testDuplicateColumnsFirstWinsLaterSkippedAndDisclosed(): void
    {
        // Eight labels on a 4-column axis: neighbours land on shared columns.
        [$rows, $notes] = self::labels(12, 2, 4, ['aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg', 'hh'], []);
        $this->assertNotSame([], $notes);
        $this->assertStringContainsString('aa', $rows[5], 'the first label survives intact');
        $this->assertStringNotContainsString('bb', $rows[5], 'a colliding later label is skipped, never painted over');
        $this->assertStringContainsString('"bb"', $notes[0]);
        $this->assertStringContainsString('"aa"', $notes[0]);
    }

    public function testLastAnchoredLabelDisplacesItsNeighbour(): void
    {
        [$rows, $notes] = self::labels(40, 3, 30, ['start', 'middle-label', 'x', 'end-of-axis!!'], []);
        $this->assertStringContainsString('end-of-axis!!', $rows[5], 'the axis end value always survives');
        $this->assertNotSame([], $notes);
    }

    public function testYLabelWiderThanGutterIsClampedAndNeverStampsTheAxis(): void
    {
        // gutter = cols 0..xOrigin-2 = 3 columns; "123456" cannot fit.
        [$rows, $notes] = self::labels(20, 4, 12, [], ['123456']);
        $this->assertSame('123', $rows[0], 'ends at col 2; col 3 (xOrigin-1) stays a gap, col 4 is the axis');
        $this->assertSame(1, count($notes));
        $this->assertStringContainsString('"123456"', $notes[0]);
        $this->assertStringContainsString('"123"', $notes[0]);
    }

    public function testYLabelFittingTheGutterIsUntouched(): void
    {
        [$rows, $notes] = self::labels(20, 6, 12, [], ['12']);
        $this->assertSame([], $notes);
        $this->assertStringStartsWith('   12', $rows[0]);
    }

    public function testYLabelWithNoGutterIsDropped(): void
    {
        [$rows, $notes] = self::labels(20, 1, 12, [], ['9']);
        $this->assertSame('', trim($rows[0]));
        $this->assertCount(1, $notes);
    }

    public function testLineChartExposesTheDisclosuresAndLeavesCleanChartsEmpty(): void
    {
        $clean = LineChart::new([1.0, 2.0, 3.0], 30, 8);
        $this->assertSame([], $clean->labelDisclosures());
    }

    public function testLineChartDisclosesCollidingXLabels(): void
    {
        $c = LineChart::new([1.0, 2.0, 3.0, 4.0], 20, 8)
            ->withAxes(true)
            ->withXLabels(['first-long', 'second-long', 'third-long', 'fourth-long']);
        $notes = $c->labelDisclosures();
        $this->assertNotSame([], $notes);
        $plain = Ansi::strip($c->view());
        $this->assertStringContainsString('fourth-long', $plain, 'right-anchored end label survives');
        $this->assertStringNotContainsString('second-long', $plain);
        $this->assertStringNotContainsString('first-l', $plain, 'no clipped hybrid of an overdrawn neighbour');
    }

    public function testLineChartGutterGrowsForWideYLabelsSoNothingIsClipped(): void
    {
        $c = LineChart::new([1.0, 2.0, 3.0], 30, 8)->withAxes(true)->withYLabels(['1234567890123456789', '0']);
        $this->assertSame([], $c->labelDisclosures());
        $this->assertStringContainsString('1234567890123456789', Ansi::strip($c->view()));
    }

    public function testScatterSharedCellTakesTheDocumentedMergeGlyph(): void
    {
        $s = Scatter::new([[0, 0], [0, 0], [10, 10]], 11, 3)->withAxes(false);
        $view = Ansi::strip($s->view());
        $this->assertSame(1, substr_count($view, Scatter::OVERLAP_RUNE));
        $this->assertSame(1, substr_count($view, $s->rune), 'the lone point keeps the configured rune');
    }

    public function testScatterWithoutSharedCellsIsUnchanged(): void
    {
        $s = Scatter::new([[0, 0], [5, 5], [10, 10]], 11, 3)->withAxes(false);
        $this->assertStringNotContainsString(Scatter::OVERLAP_RUNE, $s->view());
    }
}
