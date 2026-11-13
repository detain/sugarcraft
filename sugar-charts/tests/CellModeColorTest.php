<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;
use SugarCraft\Style\Theme;

/**
 * CH-21 (sugar-charts-023): unthemed cell mode colors its strokes from
 * the legend palette when a legend is visible — the swatch promise and
 * the stroke bytes agree through the single Legend::styleForSwatchColor
 * matcher, in both directions (hex goes truecolor, off-table names stay
 * colorless). Legend-less cell mode keeps the byte-identical unstyled
 * default; themed and braille paths are unchanged.
 */
final class CellModeColorTest extends TestCase
{
    private const BLUE = "\x1b[34m";

    public function testDefaultLegendRenderColorsStrokes(): void
    {
        $out = LineChart::new([1, 2, 3, 4], 20, 5)
            ->withDataset('s2', [2, 3, 4, 5])
            ->withDataset('s3', [4, 3, 2, 1])
            ->withDataset('s4', [1, 3, 2, 4])
            ->withLegend(true)
            ->view();
        // blue + (colorless orange) + green slots of the auto rotation:
        // >=3 colored stroke cells in each real color (acceptance floor).
        $this->assertGreaterThanOrEqual(3, substr_count($out, self::BLUE));
        $this->assertGreaterThanOrEqual(3, substr_count($out, "\x1b[32m"));
    }

    public function testSwatchAndStrokeShareColorBytesPerSeries(): void
    {
        $out = LineChart::new([1, 2, 3, 4], 20, 5)
            ->withDataset('s2', [2, 3, 4, 5])
            ->withLegend(true)
            ->view();
        $lines = explode("\n", $out);
        $swatchLine = null;
        foreach ($lines as $line) {
            if (str_contains($line, "\x1b[39m") || str_contains($line, '█')) {
                $swatchLine = $line;
            }
        }
        $this->assertNotNull($swatchLine, 'legend row with swatch must exist');
        $this->assertStringContainsString(self::BLUE . '█', $swatchLine, 'swatch uses the COLOR_MAP slot');
        $strokeArmed = array_filter($lines, static fn(string $l): bool => str_contains($l, self::BLUE) && !str_contains($l, '█'));
        $this->assertNotEmpty($strokeArmed, 'strokes must emit the identical color sequence, not a truecolor lookalike');
    }

    public function testLegendlessCellModeStaysByteIdenticalUnstyled(): void
    {
        $out = LineChart::new([1, 2, 3, 4], 20, 5)
            ->withDataset('s2', [2, 3, 4, 5])
            ->view();
        $this->assertStringNotContainsString("\x1b[", $out, 'no legend, no theme: the historical zero-escape default must hold');
    }

    public function testOffTableColorIsColorlessInBothDirections(): void
    {
        // 'orange' is a DATASET_COLORS entry but no COLOR_MAP key: the
        // swatch renders default-fg and the stroke must stay unstyled —
        // byte-equal promise, not a colored stroke the legend never sold.
        $out = LineChart::new([1, 2, 3, 4], 20, 5)
            ->withDataset('s2', [2, 3, 4, 5]) // auto legend color: blue
            ->withDataset('s3', [4, 3, 2, 1]) // auto legend color: orange
            ->withLegend(true)
            ->view();
        // The only escape bytes present are the blue slot (s2) and swatch
        // chrome — orange never reaches the stroke as a truecolor lookalike.
        $this->assertStringNotContainsString("\x1b[38;2;", $out);
        $this->assertStringNotContainsString("\x1b[33m", $out);
    }

    public function testHexSwatchGoesTruecolorOnTheStroke(): void
    {
        $out = LineChart::new([1, 2, 3, 4], 20, 5)
            ->withDataset('h', [2, 3, 4, 5])
            ->withLegendItems([['label' => 'h', 'color' => '#00ff00']])
            ->withLegend(true)
            ->view();
        $seq = "\x1b[38;2;0;255;0m";
        $this->assertGreaterThanOrEqual(2, substr_count($out, $seq), 'stroke cells AND the swatch share the truecolor bytes');
    }

    public function testThemedCellPathUnchanged(): void
    {
        $chart = LineChart::new([1, 2, 3, 4], 20, 5)
            ->withDataset('s2', [2, 3, 4, 5])
            ->withLegend(true)
            ->withTheme(Theme::dracula());
        // Theme palette owns stroke colors when set — the legend match
        // must not intrude on a themed render (dracula primary hex):
        $out = $chart->view();
        $this->assertStringContainsString("\x1b[38;2;", $out);
        $this->assertStringNotContainsString(self::BLUE . '█', $out, 'themed swatches are palette hex, not COLOR_MAP slots');
    }

    public function testSeriesColorFnDefaultFlowsThroughLegendStyle(): void
    {
        // A resolver returning null falls to the series default — which is
        // now the legend color (docblock :411 promise).
        $out = LineChart::new([1, 2, 3, 4], 20, 5)
            ->withDataset('s2', [2, 3, 4, 5])
            ->withLegend(true)
            ->withSeriesColorFn(static fn(): ?Color => null)
            ->view();
        $this->assertStringContainsString(self::BLUE, $out);
    }

    public function testSwatchMatcherContract(): void
    {
        // The matcher itself, table + hex + miss, one truth per branch.
        $red = Legend::styleForSwatchColor('red');
        $this->assertInstanceOf(Style::class, $red);
        $this->assertStringContainsString("\x1b[31m", (string) $red->render('x'));
        $this->assertNull(Legend::styleForSwatchColor('default'));
        $this->assertNull(Legend::styleForSwatchColor('orange'));
        $this->assertNull(Legend::styleForSwatchColor('nonsense'));
        $hex = Legend::styleForSwatchColor('#abc');
        $this->assertInstanceOf(Style::class, $hex);
        $this->assertStringContainsString("\x1b[38;2;170;187;204m", (string) $hex->render('x'));
    }

    public function testSwatchMatcherAndIndicatorAgreePerTableEntry(): void
    {
        // Drift guard for the private COLOR_MAP: every table entry's raw
        // swatch SGR must equal the matcher Style's SGR (coloredIndicator
        // and strokes share one contract even though they wrap differently).
        $ref = new \ReflectionClass(Legend::class);
        $map = $ref->getConstant('COLOR_MAP');
        $this->assertIsArray($map);
        foreach ($map as $name => $code) {
            $style = Legend::styleForSwatchColor($name);
            if ($code === "\x1b[39m") {
                $this->assertNull($style, "'default' must resolve colorless");
                continue;
            }
            $this->assertInstanceOf(Style::class, $style, "table color {$name} must resolve");
            $rendered = (string) $style->render('█');
            $this->assertStringStartsWith($code, $rendered, "stroke SGR for {$name} must open with the swatch code");
        }
    }
}
