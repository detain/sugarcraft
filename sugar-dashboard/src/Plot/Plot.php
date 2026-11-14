<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Foundation\Buffer;
use SugarCraft\Dashboard\Foundation\Rect;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Foundation\SizedItem;
use SugarCraft\Dashboard\Foundation\Drawable;
use SugarCraft\Dashboard\Foundation\Theme;
use SugarCraft\Dashboard\Plot\Braille\BrailleCanvas;
use SugarCraft\Dashboard\Plot\Braille\BrailleMatrix;
use SugarCraft\Dashboard\Plot\Chart\AxisLabelFormatter;

/**
 * A line/scatter chart rendered using braille characters for 2x4 resolution.
 *
 * Supports two modes:
 * - LineChart: connected points with lines
 * - ScatterPlot: unconnected points only
 *
 * Colour precedence (finding sugar-dashboard-234): the global colour set
 * via {@see self::withColor()} always wins; a per-cell colour carries
 * through to both entry paths only when no global colour is set.
 *
 * Both entry paths paint the SAME geometry (findings sugar-dashboard-183/
 * 184): {@see self::buildPlot()} owns the gutter, the window and the
 * braille canvas, so draw(Buffer) and render() can no longer drift.
 *
 * Mirrors termui widgets/plot.go
 */
final class Plot implements SizedItem, Drawable
{
    use AxisLabelFormatter;

    public const MODE_LINE = 'line';
    public const MODE_SCATTER = 'scatter';

    private string $mode = self::MODE_LINE;
    private bool $showAxes = true;
    private int $horizontalScale = 1;

    /** @var (float|null)[] */
    private array $data = [];
    private float $minValue = 0;
    private float $maxValue = 100;
    private bool $manualRange = false;
    private int $width = 80;
    private int $height = 24;
    private ?Rect $rect = null;
    private ?Color $color = null;

    public function __construct(array $data = [], int $width = 80, int $height = 24)
    {
        $this->data = $data;
        $this->width = $width;
        $this->height = $height;
        $this->computeValueRange();
    }

    public static function new(array $data = [], int $width = 80, int $height = 24): self
    {
        return new self($data, $width, $height);
    }

    // ─── Mode ─────────────────────────────────────────────────────

    /**
     * Set the plot mode.
     *
     * Throws on anything outside the documented vocabulary: the old code
     * stored unknown strings silently and the renderer's `=== MODE_LINE`
     * test quietly drew the scatter path, so a typo became an invisible
     * mode change (finding sugar-dashboard-195). Absent mode still
     * defaults to line via the property initializer.
     */
    public function withMode(string $mode): self
    {
        if ($mode !== self::MODE_LINE && $mode !== self::MODE_SCATTER) {
            throw new \InvalidArgumentException(
                'Unknown plot mode "' . $mode . '"; valid modes: ' . self::MODE_LINE . ', ' . self::MODE_SCATTER . '.'
            );
        }
        $clone = clone $this;
        $clone->mode = $mode;
        return $clone;
    }

    public function withShowAxes(bool $show): self
    {
        $clone = clone $this;
        $clone->showAxes = $show;
        return $clone;
    }

    public function withHorizontalScale(int $scale): self
    {
        $clone = clone $this;
        $clone->horizontalScale = max(1, $scale);
        return $clone;
    }

    public function withColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->color = $color;
        return $clone;
    }

    public function withData(array $data): self
    {
        $clone = clone $this;
        $clone->data = $data;
        // An explicit manual range survives data replacement
        // (finding sugar-dashboard-185: auto-scale used to clobber it).
        if (!$clone->manualRange) {
            $clone->computeValueRange();
        }
        return $clone;
    }

    public function withMinValue(float $min): self
    {
        if ($min > $this->maxValue) {
            throw new \InvalidArgumentException(
                'Plot minimum ' . $min . ' exceeds current maximum ' . $this->maxValue . '; set the maximum first.'
            );
        }
        $clone = clone $this;
        $clone->minValue = $min;
        $clone->manualRange = true;
        return $clone;
    }

    public function withMaxValue(float $max): self
    {
        if ($max < $this->minValue) {
            throw new \InvalidArgumentException(
                'Plot maximum ' . $max . ' is below current minimum ' . $this->minValue . '; set the minimum first.'
            );
        }
        $clone = clone $this;
        $clone->maxValue = $max;
        $clone->manualRange = true;
        return $clone;
    }

    // ─── Sizer / Drawable ─────────────────────────────────────────

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function innerSize(): array
    {
        return [$this->width, $this->height];
    }

    public function rect(): Rect
    {
        return $this->rect ?? new Rect(0, 0, $this->width - 1, $this->height - 1);
    }

    public function setRect(Rect $rect): self
    {
        $clone = clone $this;
        $clone->rect = $rect;
        $clone->width = $rect->dx();
        $clone->height = $rect->dy();
        return $clone;
    }

    public function draw(Buffer $buffer): void
    {
        [$bufWidth, $bufHeight] = $buffer->innerSize();

        if ($this->width - ($this->showAxes ? 2 : 0) <= 0 || $this->height - ($this->showAxes ? 3 : 0) <= 0) {
            return;
        }

        [$canvas, $gutter, $innerWidth, $innerHeight, $yLabels, $windowStart, $lastPainted] = $this->buildPlot();

        $rect = $this->rect();
        $globalStyle = $this->color !== null
            ? new \SugarCraft\Dashboard\Foundation\Style(foreground: $this->color)
            : new \SugarCraft\Dashboard\Foundation\Style();

        // Canvas origin offset within the buffer - the gutter is the label
        // column, however wide the widest label needs it (finding 082: the
        // fixed 2-cell gutter let long labels overrun the plot width).
        $originX = $rect->minX + $gutter;
        $originY = $rect->minY + ($this->showAxes ? 1 : 0);

        // Write braille cells directly into the buffer grid (in-place mutation, matching Buffer::draw() pattern)
        foreach ($canvas->cells() as [$cellX, $cellY, $bits, $cellColor]) {
            $targetX = $originX + $cellX;
            $targetY = $originY + $cellY;

            if ($targetX < 0 || $targetY < 0 || $targetX >= $bufWidth || $targetY >= $bufHeight) {
                continue;
            }

            $rune = BrailleMatrix::rune($bits);

            // Global colour always wins; the per-cell colour applies only
            // when no global colour is set (documented in the class block).
            $style = ($cellColor !== null && $this->color === null)
                ? $globalStyle->withForeground($cellColor)
                : $globalStyle;

            $buffer->grid[$targetY][$targetX] = new \SugarCraft\Dashboard\Foundation\Cell($rune, $style);
        }

        // Axes labels via setString (faster for text than per-cell setCell)
        // Only write axes when something was actually painted (shared null
        // filter with render(), finding sugar-dashboard-184).
        if ($this->showAxes && $lastPainted !== null) {
            // Y-axis labels (left gutter column)
            $yLabelCount = count($yLabels);
            for ($y = 0; $y < $innerHeight && $y < $yLabelCount; $y++) {
                $posY = $rect->minY + 1 + $y;
                if ($posY > $rect->maxY) {
                    break;
                }
                $this->writeString($buffer, $rect->minX, $posY, str_pad($yLabels[$y], $gutter), $globalStyle, $bufWidth, $bufHeight);
            }

            // X-axis labels (bottom two rows), window-honest
            [$xStart, $xEnd] = $this->generateXLabels($windowStart, $lastPainted);
            $pad = str_repeat(' ', $gutter);
            $this->writeString($buffer, $rect->minX, $rect->minY + 1 + $innerHeight, $pad . $xStart, $globalStyle, $bufWidth, $bufHeight);
            $this->writeString($buffer, $rect->minX, $rect->minY + 2 + $innerHeight, $pad . $xEnd, $globalStyle, $bufWidth, $bufHeight);
        }
    }

    /**
     * Write a string directly into the buffer grid (in-place, matching Buffer::draw() pattern).
     */
    private function writeString(Buffer $buffer, int $x, int $y, string $str, \SugarCraft\Dashboard\Foundation\Style $style, int $bufWidth, int $bufHeight): void
    {
        if ($x < 0 || $y < 0 || $x >= $bufWidth || $y >= $bufHeight) {
            return;
        }

        foreach (mb_str_split($str) as $i => $char) {
            $posX = $x + $i;
            if ($posX >= $bufWidth) {
                break;
            }
            $buffer->grid[$y][$posX] = new \SugarCraft\Dashboard\Foundation\Cell($char, $style);
        }
    }

    // ─── Rendering ────────────────────────────────────────────────

    /**
     * Compute min/max value range from data.
     */
    private function computeValueRange(): void
    {
        $filtered = array_filter($this->data, fn($v) => $v !== null);
        if (empty($filtered)) {
            $this->minValue = 0;
            $this->maxValue = 100;
            return;
        }

        $this->minValue = min($filtered);
        $this->maxValue = max($filtered);

        // Ensure some range even if all values are identical
        if ($this->maxValue === $this->minValue) {
            $this->maxValue = $this->minValue + 1;
        }
    }

    /**
     * Shared geometry for BOTH entry paths (findings sugar-dashboard-082,
     * 183, 184, 197): the old draw()/render() pair duplicated the canvas
     * math and drifted - differing null filters decided when axes were
     * painted, labels wider than the fixed two-cell gutter overran the
     * plot width, and samples beyond the canvas all clamped into the last
     * column, piling history into a solid vertical bar. This builder owns
     * the numbers once: the gutter is as wide as the widest label (never
     * less than the historical 2 cells), the canvas gets every remaining
     * column, and a sample stream longer than the canvas paints as a
     * trailing window (the DualSampleGraph::frame() precedent in this lib).
     *
     * @return array{0:BrailleCanvas,1:int,2:int,3:int,4:list<string>,5:int,6:?int}
     *         [canvas, gutterCells, innerWidth, innerHeight, yLabels, windowStart, lastPaintedIndex]
     */
    private function buildPlot(): array
    {
        // One canvas row less than the old string-only path: axes spend a
        // top row, two x-label rows and nothing else - draw() offsets its
        // canvas origin by one under axes, so render() must too or the two
        // entry paths paint different rectangles (finding 183).
        $innerHeight = $this->height - ($this->showAxes ? 3 : 0);
        $yLabels = $this->showAxes ? $this->generateYLabels($innerHeight) : [];

        $gutter = 0;
        if ($this->showAxes) {
            $gutter = 2;
            foreach ($yLabels as $label) {
                $gutter = max($gutter, \SugarCraft\Core\Util\Width::string($label));
            }
        }
        $innerWidth = $this->width - $gutter;

        $dotWidth = $innerWidth * 2;
        $dotHeight = $innerHeight * 4;
        $canvas = BrailleCanvas::new($dotWidth, $dotHeight);

        $step = $this->horizontalScale * 2;
        $count = count($this->data);
        // Trailing window: only the samples that fit land on the canvas.
        $capacity = intdiv($dotWidth - 1, $step) + 1;
        $windowStart = max(0, $count - $capacity);

        $verticalScale = ($this->maxValue - $this->minValue) / max(1, $innerHeight - 1);

        $prevX = null;
        $prevY = null;
        $lastPainted = null;

        foreach (array_slice($this->data, $windowStart) as $offset => $value) {
            if ($value === null) {
                $prevX = null;
                $prevY = null;
                continue;
            }

            $x = min($offset * $step, $dotWidth - 1);
            $rawY = (int) (($value - $this->minValue) / max(0.001, $verticalScale)) * 4;
            $y = max(0, min(($dotHeight - 1) - $rawY, $dotHeight - 1));

            if ($this->mode === self::MODE_LINE && $prevX !== null && $prevY !== null) {
                $canvas = $canvas->setLine($prevX, $prevY, $x, $y, $this->color);
            } else {
                $canvas = $canvas->setPoint($x, $y, $this->color);
            }

            $prevX = $x;
            $prevY = $y;
            $lastPainted = $windowStart + $offset;
        }

        return [$canvas, $gutter, $innerWidth, $innerHeight, $yLabels, $windowStart, $lastPainted];
    }

    /**
     * Render the plot as a string.
     */
    public function render(): string
    {
        if ($this->width - ($this->showAxes ? 2 : 0) <= 0 || $this->height - ($this->showAxes ? 3 : 0) <= 0) {
            return '';
        }

        if ($this->data === []) {
            return str_repeat("\n", $this->height - 1);
        }

        [$canvas, $gutter, $innerWidth, $innerHeight, $yLabels, $windowStart, $lastPainted] = $this->buildPlot();

        $output = $canvas->render();

        // If not showing axes, return the canvas alone - every row padded
        // to the allocation (the canvas rtrim's content lines, but the plot
        // contract is one exact row width so draw() and render() describe
        // the same rectangle).
        if (!$this->showAxes) {
            return implode("\n", array_map(
                static fn(string $line): string => \SugarCraft\Core\Util\Width::padRight($line, $innerWidth),
                explode("\n", $output),
            ));
        }

        // Combine canvas output with axes - the gutter is label-honest, so
        // a '-1.5M' fixture paints 5 cells and the canvas shrinks to match
        // (finding sugar-dashboard-082: str_pad never truncated and every
        // row overran the allocation).
        $canvasLines = explode("\n", $output);
        // Top axis row - the canvas origin under draw() sits one row down
        // when axes are on; render must spend the same row or the two paths
        // paint different rectangles (finding 183 parity, again).
        $resultLines = [str_repeat(' ', $gutter + $innerWidth)];

        for ($y = 0; $y < $innerHeight; $y++) {
            $yLabel = $yLabels[$y] ?? '';
            $canvasLine = $canvasLines[$y] ?? '';
            $resultLines[] = str_pad($yLabel, $gutter) . \SugarCraft\Core\Util\Width::padRight($canvasLine, $innerWidth);
        }

        // X-axis labels (bottom row + padding row), window-honest: they
        // show the FIRST painted sample index and the LAST one, not a '0'
        // when history was windowed away. Padded to the same exact width.
        [$xStart, $xEnd] = $this->generateXLabels($windowStart, $lastPainted);
        $pad = str_repeat(' ', $gutter);
        $resultLines[] = \SugarCraft\Core\Util\Width::padRight($pad . $xStart, $gutter + $innerWidth);
        $resultLines[] = \SugarCraft\Core\Util\Width::padRight($pad . $xEnd, $gutter + $innerWidth);

        $output = implode("\n", $resultLines);

        // If color was applied, ensure reset code at the very end
        if ($this->color !== null) {
            $output .= Ansi::reset();
        }

        return $output;
    }

    /**
     * Generate Y-axis labels.
     *
     * @return list<string>
     */
    private function generateYLabels(int $height): array
    {
        $labels = [];
        $range = $this->maxValue - $this->minValue;

        for ($i = 0; $i < $height; $i++) {
            // Labels from top to bottom (max to min)
            $value = $this->maxValue - (($i / max(1, $height - 1)) * $range);
            $labels[] = $this->formatYLabel($value);
        }

        return $labels;
    }

    /**
     * Generate X-axis labels: the first painted sample and the last one
     * (null samples past the last painted point do not claim the axis).
     *
     * @return array{0:string,1:string}
     */
    private function generateXLabels(int $windowStart, ?int $lastPainted): array
    {
        if ($lastPainted === null) {
            return ['', ''];
        }

        return [(string) $windowStart, (string) $lastPainted];
    }

    /**
     * Apply a theme to this plot, using the theme's primary color.
     */
    public function withTheme(Theme $theme): self
    {
        return $this->withColor($theme->primary());
    }
}
