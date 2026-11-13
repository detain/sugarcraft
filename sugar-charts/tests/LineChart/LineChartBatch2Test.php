<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Style\Theme;

/**
 * Pins for the lib-reviews-1103 batch-2 fixes on LineChart: the reserved
 * `_primary` dataset name now throws instead of silently shadowing (M8a),
 * the x-minimum point survives the axis frame (M8b), unthemed cell-mode
 * datasets separate by rotating glyphs (M13), and the canvas discriminant
 * is plain `?object` so the sugar-dashboard type is no longer required at
 * runtime (M1).
 */
final class LineChartBatch2Test extends TestCase
{
    public function testReservedPrimaryDatasetNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dataset name _primary is reserved for the primary data series');
        // CH-20 (sugar-charts-091): the ctor is private now — the same door
        // is reached through the public withDataset ingress.
        LineChart::new([1.0, 2.0], 8, 3)->withDataset('_primary', [3.0, 4.0]);
    }

    public function testReservedPrimaryDatasetPointNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dataset-point name _primary is reserved for the primary data series');
        LineChart::new([1.0, 2.0], 8, 3)->withDatasetPoint('_primary', 'o');
    }

    public function testWithDatasetRejectsReservedNameThroughTheCopyDoor(): void
    {
        // withDataset() is the streaming ingress; the guard() applied to
        // the name is identity on '_primary', so the constructor throw
        // still fires.
        $this->expectException(\InvalidArgumentException::class);
        LineChart::new([1.0, 2.0])->withDataset('_primary', [5.0, 6.0]);
    }

    public function testXMinimumPointSurvivesTheAxisFrame(): void
    {
        // M8b: before the fix the vertical axis occupied the first data
        // column and painted over the leftmost sample. 0 (minimum) sits
        // at the bottom-left of the plot when axes are on.
        $chart = LineChart::new([0.0, 10.0], 8, 3)->withAxes(true);
        $rows = explode("\n", $chart->view());
        // Bottom data row (row index height-2 above the axis line): the
        // '*' for x=0 must be present to the right of the axis bar.
        $joined = implode("\n", $rows);
        $this->assertStringContainsString('*', $joined);
        $this->assertSame(2, substr_count($joined, '*'), 'both endpoints render (one per sample)');
        $this->assertStringContainsString("\u{2502}", $joined, 'the axis itself is still drawn');
    }

    public function testUnthemedCellModeRotatesDatasetGlyphs(): void
    {
        $chart = LineChart::new([1.0, 2.0, 3.0, 4.0], 12, 4)
            ->withDataset('b', [4.0, 3.0, 2.0, 1.0])
            ->withDataset('c', [2.0, 4.0, 1.0, 3.0]);
        $view = $chart->view();

        // Primary keeps its configured '*' rune; the first extra dataset
        // takes DATASET_RUNES[1] '+', the second DATASET_RUNES[2] 'o'.
        $this->assertStringContainsString('*', $view);
        $this->assertStringContainsString('+', $view);
        $this->assertStringContainsString('o', $view);
        $this->assertStringNotContainsString("\x1b", $view, 'unthemed cell mode emits no color');
    }

    public function testExplicitDatasetPointStillWinsOverRotation(): void
    {
        $chart = LineChart::new([1.0, 2.0, 3.0], 8, 3)
            ->withDataset('b', [3.0, 2.0, 1.0])
            ->withDatasetPoint('b', '@');
        $this->assertStringContainsString('@', $chart->view());
    }

    public function testDatasetPaletteIsColorBlindSafe(): void
    {
        $colors = (new \ReflectionClass(LineChart::class))->getConstant('DATASET_COLORS');
        $this->assertIsArray($colors);
        // The old first pair (red, green) is the classic deutan collision;
        // the lead pair is now blue/orange — distinguishable under all three
        // common color-vision deficiencies. Full order pinned: a deliberate
        // drift from the historic palette, so the cycle itself is the spec.
        $this->assertSame(['blue', 'orange', 'green', 'red', 'purple', 'cyan'], $colors);

        $runes = (new \ReflectionClass(LineChart::class))->getConstant('DATASET_RUNES');
        $this->assertSame(['*', '+', 'o', 'x', '#', '.'], $runes);
    }

    public function testBrailleModeSelectsRasterAndCanvasRejectsForeignObjects(): void
    {
        // CH-04: braille is a boolean mode; the canvas seat is typed
        // CellGrid — an arbitrary object no longer selects anything and is
        // refused at the door. withCanvas(null) clears the bridge.
        $cell = LineChart::new([1.0, 5.0, 2.0, 8.0], 10, 4);
        $braille = $cell->withBraille(true);
        $this->assertNotSame($cell->view(), $braille->view());
        $this->assertTrue($braille->braille);

        $cleared = $braille->withBraille(false);
        $this->assertFalse($cleared->braille);
        $this->assertSame($cell->view(), $cleared->view());

        $this->expectException(\TypeError::class);
        $cell->withCanvas(new \stdClass());
    }

    public function testSourceCarriesNoDashboardRuntimeDependency(): void
    {
        // M1 structural pin: every src file must resolve without the
        // (now dev-only) sugar-dashboard package on the autoload path.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src'));
        $needle = 'SugarCraft\\Dashboard';
        foreach ($files as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $this->assertStringNotContainsString($needle, (string) file_get_contents($f->getPathname()), $f->getPathname());
            }
        }
    }

    public function testThemeStillDrivesSeriesColorsAfterConstReorder(): void
    {
        // Themed path unchanged by M13: colors come from the theme
        // palette, not DATASET_COLORS, so no rune/glyph rotation assertion
        // here — just prove the themed render keeps its SGR bytes.
        $chart = LineChart::new([1.0, 2.0], 8, 3)->withTheme(Theme::dark());
        $this->assertStringContainsString("\x1b[38;", $chart->view());
    }
}
