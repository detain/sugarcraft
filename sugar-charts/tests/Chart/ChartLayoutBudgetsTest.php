<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Chart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\Support\UntrustedText;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * lib-reviews-1103 M4/M5: composition must measure CELLS, not bytes, and
 * every free-text ingress (title, axis labels, legend labels) carries a
 * width budget so oversized strings cannot multiply the canvas out.
 */
#[CoversClass(Chart::class)]
final class ChartLayoutBudgetsTest extends TestCase
{
    private const WIDTH = 10;

    /** @return list<string> */
    private static function rows(string $view): array
    {
        return explode("\n", $view);
    }

    public function testOverlongTitleIsClippedToCanvasWidth(): void
    {
        $view = LineChart::new([1.0, 2.0], self::WIDTH, 3)
            ->withTitle(str_repeat('x', 200))
            ->view();

        self::assertLessThanOrEqual(self::WIDTH, Width::of(self::rows($view)[0]));
    }

    public function testWideCharacterTitleCannotEscapeCanvas(): void
    {
        // Byte str_pad (the pre-fix shape) saw 60 bytes for a 20-cell CJK
        // title at width 10, decided "already long enough", and emitted a
        // 20-cell row; cell-aware truncation keeps it at 10.
        $view = LineChart::new([1.0, 2.0], self::WIDTH, 3)
            ->withTitle(str_repeat('一', 10))
            ->view();

        self::assertLessThanOrEqual(self::WIDTH, Width::of(self::rows($view)[0]));
    }

    public function testYLabelIsBudgetedToQuarterCanvas(): void
    {
        $view = LineChart::new([1.0, 2.0], self::WIDTH, 3)
            ->withYLabel(str_repeat('y', 100))
            ->view();

        // CH-03 (sugar-charts-030): the label rides ONE centered row; the
        // other rows carry the same-width blank gutter. Budget cap holds.
        $budget = intdiv(self::WIDTH, 4);
        $rows   = self::rows($view);
        $labelled = 0;
        foreach ($rows as $row) {
            self::assertStringNotContainsString(str_repeat('y', $budget + 1), $row);
            if (str_starts_with($row, str_repeat('y', $budget) . ' ')) {
                $labelled++;
                continue;
            }
            self::assertStringStartsWith(str_repeat(' ', $budget) . ' ', $row);
        }
        self::assertSame(1, $labelled);
    }

    public function testXLabelIsClippedToCanvasWidth(): void
    {
        $view = LineChart::new([1.0, 2.0], self::WIDTH, 3)
            ->withXLabel(str_repeat('z', 200))
            ->view();

        $last = self::rows($view)[count(self::rows($view)) - 1];
        self::assertLessThanOrEqual(self::WIDTH, Width::of($last));
    }

    public function testLegendLabelsAreCappedAtTheSharedBudget(): void
    {
        $view = Legend::new([['label' => str_repeat('L', 300), 'color' => 'red']])->view();

        self::assertSame(UntrustedText::MAX_LABEL_CELLS, substr_count($view, 'L'));
    }

    public function testRightLegendColumnIsCellAlignedAcrossStyledRows(): void
    {
        // Two datasets render differently-long styled rows; byte-based
        // str_pad added no padding at all (byte length already exceeded the
        // column budget once SGR overhead counted), so the legend's
        // indicator drifted row to row. Cell-aware padding pins one column.
        $chart = LineChart::new([1.0, 3.0, 2.0], 24, 5)
            ->withDataset('alpha', [2.0, 1.0, 3.0])
            ->withDataset('beta', [3.0, 2.0, 1.0])
            ->withLegend(true)
            ->withLegendPosition(Position::Right);

        $view = $chart->view();
        $indicator = "\u{2588}"; // █ default legend indicator
        $columns = [];

        foreach (self::rows($view) as $row) {
            $clean = Ansi::strip($row);
            $byte = mb_strpos($clean, $indicator);
            if ($byte === false) {
                continue;
            }
            $columns[] = Width::of(mb_substr($clean, 0, $byte));
        }

        self::assertNotEmpty($columns, 'expected legend indicators in composed output');
        self::assertCount(1, array_unique($columns), 'every legend row must start at the same display column');
    }

    public function testDeadCompositionLocalsAreGone(): void
    {
        // senior-n1/n2: the byte-length helpers existed only to feed the old
        // str_pad math; structural pin keeps them from regrowing.
        $chartSrc = (string) file_get_contents(__DIR__ . '/../../src/Chart/Chart.php');
        $extrasSrc = (string) file_get_contents(__DIR__ . '/../../src/Chart/ChartExtras.php');

        self::assertStringNotContainsString('$chartHeight = count($chartLines)', $chartSrc);
        self::assertStringNotContainsString('$legendHeight = count($legendLines)', $chartSrc);
        self::assertStringNotContainsString('$titleLen = mb_strlen', $extrasSrc);
        self::assertStringNotContainsString('STR_PAD_RIGHT', $chartSrc);
        self::assertStringNotContainsString('STR_PAD_BOTH', $chartSrc);
        self::assertStringNotContainsString('STR_PAD_RIGHT', $extrasSrc);
        self::assertStringNotContainsString('STR_PAD_BOTH', $extrasSrc);
    }
}
