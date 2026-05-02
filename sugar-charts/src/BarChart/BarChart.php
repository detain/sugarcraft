<?php

declare(strict_types=1);

namespace CandyCore\Charts\BarChart;

/**
 * Vertical bar chart drawn with `█` blocks. Bars are spaced one column
 * apart and labels are written underneath, truncated to fit when too
 * long. Y-axis range is computed from the data unless explicit
 * {@see withMin()} / {@see withMax()} are provided.
 *
 * ```php
 * echo BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], width: 12, height: 5)->view();
 * ```
 */
final class BarChart
{
    /** @param list<Bar> $bars */
    private function __construct(
        public readonly array $bars,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly bool $showLabels,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('bar chart width/height must be >= 0');
        }
    }

    /**
     * Construct from either an array of `[label, value]` tuples, an array
     * keyed `label => value`, or a list of {@see Bar} instances.
     *
     * @param iterable<mixed> $bars
     */
    public static function new(iterable $bars = [], int $width = 40, int $height = 8): self
    {
        return new self(self::coerceBars($bars), $width, $height, null, null, true);
    }

    /** @param iterable<mixed> $bars */
    public function withBars(iterable $bars): self
    {
        return new self(self::coerceBars($bars), $this->width, $this->height, $this->min, $this->max, $this->showLabels);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('bar chart width/height must be >= 0');
        }
        return new self($this->bars, $w, $h, $this->min, $this->max, $this->showLabels);
    }

    public function withMin(?float $m): self        { return new self($this->bars, $this->width, $this->height, $m, $this->max, $this->showLabels); }
    public function withMax(?float $m): self        { return new self($this->bars, $this->width, $this->height, $this->min, $m, $this->showLabels); }
    public function withShowLabels(bool $on): self  { return new self($this->bars, $this->width, $this->height, $this->min, $this->max, $on); }

    public function view(): string
    {
        if ($this->bars === [] || $this->width === 0 || $this->height === 0) {
            return '';
        }

        // Drop trailing bars that don't fit the width budget. With
        // colW=1 + gap=1, at most `(width + 1) / 2` bars fit; without a
        // gap, at most `width` bars fit. Prefer keeping every bar when
        // gaps fit, otherwise fall back to packing as many as `width`
        // allows so the rendered output never exceeds the requested
        // width.
        $bars       = $this->bars;
        $count      = count($bars);
        $withGapMax = intdiv($this->width + 1, 2);
        if ($count > $withGapMax) {
            $maxCount = max(0, min($count, $this->width));
            if ($count > $maxCount) {
                $bars  = array_slice($bars, 0, $maxCount);
                $count = $maxCount;
            }
        }
        $values = array_map(static fn(Bar $b): float => $b->value, $bars);
        if ($values === []) {
            return '';
        }

        $min = $this->min ?? min(min($values), 0.0);
        $max = $this->max ?? max($values);
        if ($max === $min) {
            $max = $min + 1.0;
        }

        // Distribute available width across the surviving bars; reserve a
        // 1-cell gap when there's room. Bars expand to fill the remainder
        // so labels can render in full when possible.
        $gap   = $count > 1 && $this->width >= 2 * $count - 1 ? 1 : 0;
        $avail = $this->width - ($count - 1) * $gap;
        $colW  = max(1, intdiv($avail, max(1, $count)));

        // Reserve one row for labels only if the chart is tall enough to
        // also contain a body. height=1 + showLabels would otherwise emit
        // 2 rows and overflow the requested height.
        $renderLabels = $this->showLabels && $this->height >= 2;
        $bodyHeight   = $renderLabels ? $this->height - 1 : $this->height;
        $heights = [];
        foreach ($values as $v) {
            $norm = ($v - $min) / ($max - $min);
            $norm = max(0.0, min(1.0, $norm));
            $heights[] = (int) round($norm * $bodyHeight);
        }

        $rows = [];
        for ($row = $bodyHeight; $row >= 1; $row--) {
            $line = '';
            foreach ($heights as $i => $h) {
                $line .= str_repeat($h >= $row ? '█' : ' ', $colW);
                if ($i !== $count - 1 && $gap > 0) {
                    $line .= str_repeat(' ', $gap);
                }
            }
            $rows[] = rtrim($line);
        }

        if ($renderLabels) {
            $labelRow = '';
            foreach ($bars as $i => $bar) {
                $label = self::truncate($bar->label, $colW);
                $label = str_pad($label, $colW, ' ', STR_PAD_RIGHT);
                $labelRow .= $label;
                if ($i !== $count - 1 && $gap > 0) {
                    $labelRow .= str_repeat(' ', $gap);
                }
            }
            $rows[] = rtrim($labelRow);
        }
        return implode("\n", $rows);
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /**
     * @param iterable<mixed> $bars
     * @return list<Bar>
     */
    private static function coerceBars(iterable $bars): array
    {
        $out = [];
        foreach ($bars as $key => $value) {
            if ($value instanceof Bar) {
                $out[] = $value;
                continue;
            }
            if (is_array($value) && count($value) === 2 && isset($value[0], $value[1])) {
                $out[] = new Bar((string) $value[0], (float) $value[1]);
                continue;
            }
            // Treat as $key => $value when the key is a string label.
            if (is_string($key)) {
                $out[] = new Bar($key, (float) $value);
                continue;
            }
            $out[] = new Bar((string) $key, (float) $value);
        }
        return $out;
    }

    private static function truncate(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max, 'UTF-8');
    }
}
