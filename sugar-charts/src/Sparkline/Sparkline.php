<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Sparkline;

use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Charts\Lang;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style as BufferStyle;
use SugarCraft\Sprinkles\Style;

/**
 * Compact single-row chart drawn with the eight Unicode block-bar
 * glyphs `▁▂▃▄▅▆▇█`. Each data point becomes one cell whose height is
 * proportional to the value within `[min, max]`.
 *
 * ```php
 * echo Sparkline::new([1, 4, 2, 7, 5])->view(); // ▁▄▂█▅
 * ```
 *
 * If the configured {@see $width} exceeds `count($data)` the line is
 * left-padded with the empty glyph; if it's smaller, only the *last*
 * `width` points are shown — a natural fit for live time-series feeds.
 */
final class Sparkline
{
    /** Index 0 = empty, 1..8 = ▁..█. */
    private const GLYPHS = [' ', '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * @param list<int|float> $data
     */
    private function __construct(
        public readonly array $data,
        public readonly int $width,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly ?Style $style = null,
        public readonly bool $autoMaxValue = true,
    ) {
        if ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('sparkline.width_nonneg'));
        }
    }

    /**
     * Construct a sparkline. `$width = -1` (the default) auto-fits the
     * width to the number of data points; pass an explicit `0` to render
     * nothing.
     *
     * @param list<int|float> $data
     */
    public static function new(array $data = [], int $width = -1): self
    {
        if ($width < 0) {
            $width = count($data);
        }
        return new self(array_values($data), $width, null, null);
    }

    /** @param list<int|float> $data */
    public function withData(array $data): self
    {
        return new self(array_values($data), $this->width, $this->min, $this->max, $this->style, $this->autoMaxValue);
    }

    public function withWidth(int $w): self
    {
        if ($w < 0) {
            throw new \InvalidArgumentException(Lang::t('sparkline.width_nonneg'));
        }
        return new self($this->data, $w, $this->min, $this->max, $this->style, $this->autoMaxValue);
    }

    public function withMin(?float $m): self { return new self($this->data, $this->width, $m, $this->max, $this->style, $this->autoMaxValue); }
    public function withMax(?float $m): self { return new self($this->data, $this->width, $this->min, $m, $this->style, $this->autoMaxValue); }

    /**
     * Style applied to every glyph in {@see view()}. Pass null to
     * render unstyled. Mirrors ntcharts' `WithStyle`.
     */
    public function withStyle(?Style $style): self
    {
        return new self($this->data, $this->width, $this->min, $this->max, $style, $this->autoMaxValue);
    }

    /**
     * Disable the implicit `max = max($data)` rescale. With auto-max
     * off, the configured {@see $max} is used verbatim and values that
     * exceed it clamp to the top glyph. Mirrors ntcharts'
     * `WithNoAutoMaxValue`.
     */
    public function withNoAutoMaxValue(bool $disable = true): self
    {
        return new self($this->data, $this->width, $this->min, $this->max, $this->style, !$disable);
    }

    // Short-form aliases.
    /** @param list<float|int> $data */
    public function data(array $data): self     { return $this->withData($data); }
    public function width(int $w): self         { return $this->withWidth($w); }
    public function min(?float $m): self        { return $this->withMin($m); }
    public function max(?float $m): self        { return $this->withMax($m); }

    /**
     * Append a single sample. Mirrors ntcharts'
     * `Sparkline::Push(value)`. The window slide in {@see view()}
     * keeps only the last `width` points, so callers can append
     * indefinitely without trimming.
     */
    public function push(int|float $value): self
    {
        $next = $this->data;
        $next[] = $value;
        return new self($next, $this->width, $this->min, $this->max, $this->style, $this->autoMaxValue);
    }

    /**
     * Append every sample in `$values`, in order. Mirrors ntcharts'
     * `Sparkline::PushAll([]float64)`.
     *
     * @param list<int|float> $values
     */
    public function pushAll(array $values): self
    {
        if ($values === []) {
            return $this;
        }
        $next = $this->data;
        foreach ($values as $v) {
            $next[] = $v;
        }
        return new self($next, $this->width, $this->min, $this->max, $this->style, $this->autoMaxValue);
    }

    /** Drop every recorded sample. Mirrors ntcharts' `Clear`. */
    public function clear(): self
    {
        return new self([], $this->width, $this->min, $this->max, $this->style, $this->autoMaxValue);
    }

    public function view(): string
    {
        $w = $this->width;
        if ($w === 0) {
            return '';
        }
        $points = $this->data;
        // Slide window: keep the last $w points.
        if (count($points) > $w) {
            $points = array_slice($points, -$w);
        }
        if ($points === []) {
            return $this->styled(str_repeat(self::GLYPHS[0], $w));
        }

        $min = $this->min ?? min($points);
        // Honour the autoMaxValue toggle: when off and a max was
        // configured, use it verbatim; otherwise auto-rescale to the
        // window's max.
        if ($this->max !== null && !$this->autoMaxValue) {
            $max = $this->max;
        } else {
            $max = $this->max ?? max($points);
            if (!$this->autoMaxValue) {
                $max = max((float) $max, (float) max($points));
            }
        }
        $range = $max - $min;

        return $this->renderBuffer($w, $points, (float) $min, $range);
    }

    private function renderBuffer(int $w, array $points, float $min, float $range): string
    {
        $buffer = Buffer::new($w, 1);
        $bufferStyle = $this->style !== null ? BufferHelper::toBufferStyle($this->style) : null;
        $missing = $w - count($points);
        $col = 0;

        // Leading empty glyphs
        for ($i = 0; $i < $missing; $i++) {
            $buffer = $buffer->withCellAt($col, 0, new Cell(self::GLYPHS[0], $bufferStyle, null, 1));
            $col++;
        }

        // Data glyphs
        foreach ($points as $v) {
            $g = self::glyph((float) $v, $min, $range);
            $gw = BufferHelper::graphemeWidth($g);
            $buffer = $buffer->withCellAt($col, 0, new Cell($g, $bufferStyle, null, $gw));
            // Wide char continuation cell
            if ($gw === 2 && $col + 1 < $w) {
                $buffer = $buffer->withCellAt($col + 1, 0, Cell::continuation());
            }
            $col++;
        }

        return $buffer->toAnsi();
    }

    private function styled(string $rendered): string
    {
        return $this->style === null ? $rendered : $this->style->render($rendered);
    }

    public function __toString(): string
    {
        return $this->view();
    }

    private static function glyph(float $v, float $min, float $range): string
    {
        if ($range <= 0.0) {
            // All points equal — render a steady mid-bar (▄) so the line
            // is visible without implying movement.
            return self::GLYPHS[4];
        }
        $norm = ($v - $min) / $range;
        $norm = max(0.0, min(1.0, $norm));
        // 8 levels above the empty cell: indices 1..8.
        $idx = (int) round($norm * 8.0);
        if ($idx < 1 && $v > $min) {
            $idx = 1;
        }
        return self::GLYPHS[$idx];
    }
}
