<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Sparkline;

use SugarCraft\Charts\Support\ColorLadder;
use SugarCraft\Charts\Support\MemoizesRender;
use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Support\Finite;
use SugarCraft\Charts\Support\FrameSize;
use SugarCraft\Charts\Support\Range;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style as BufferStyle;
use SugarCraft\Style\Style;

/**
 * Compact single-row chart drawn with the eight Unicode block-bar
 * glyphs `▁▂▃▄▅▆▇█`. Each data point becomes one cell whose height is
 * proportional to the value within `[min, max]`.
 *
 * ```php
 * echo Sparkline::new([1, 4, 2, 7, 5])->view(); // " ▄▁█▅"
 * ```
 *
 * (Measured output: the minimum value maps to `GLYPHS[0]`, a blank
 * cell, so `[1, 4, 2, 7, 5]` starts with a space, not `▁` — except with
 * a {@see withStyle()} engaged, where the floor promotes to `▁` so the
 * lowest point never drops out of the wave.)
 *
 * If the configured {@see $width} exceeds `count($data)` the line is
 * left-padded with the empty glyph; if it's smaller, only the *last*
 * `width` points are shown — a natural fit for live time-series feeds.
 */
final class Sparkline
{
    use MemoizesRender;
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
        // every ingestion door (new / withData / push / pushAll)
        // rebuilds through this constructor, so one finite sweep covers the
        // whole surface — a NaN glyph index silently picks the empty cell.
        Finite::assertAll($data);
        // an explicit range must be ordered; null ends are "auto".
        Range::pin($min, $max, 'value');
    }

    /**
     * Construct a sparkline. `$width = -1` (the default) auto-fits the
     * width to the number of data points; pass an explicit `0` to render
     * nothing. Any other negative throws — one sentinel rule shared with
     * {@see withWidth()} (sugar-charts-104).
     *
     * @param list<int|float> $data
     */
    public static function new(array $data = [], int $width = -1): self
    {
        if ($width === -1) {
            $width = count($data);
        } elseif ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('sparkline.width_nonneg'));
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
     * Accepts ntcharts' `WithNoAutoMaxValue` for API parity. The
     * configured {@see $max} is ALWAYS used verbatim when set (and
     * values exceeding it clamp to the top glyph) whether the toggle
     * is on or off; without a pin the window's max rescales either
     * way. The flag therefore changes no rendering — retained as a
     * no-op shim, not silently removed (removal is breaking).
     * See {@see view()} for the resolved expression.
     *
     * @deprecated no-effect shim — the pin it toggles is always honored;
     *             set {@see withMax()} (or clear it) instead.
     */
    public function withNoAutoMaxValue(bool $disable = true): self
    {
        return new self($this->data, $this->width, $this->min, $this->max, $this->style, !$disable);
    }

    // Short-form aliases. sugar-charts-104: zero-arg calls used to be
    // ArgumentCountError traps — each is now dual-form: with no argument
    // it READS (the accessor the naming invited), with one it returns a
    // new instance (the old setter, now deprecated in favor of the
    // withX() doors). The public readonly properties stay the canonical
    // read surface; clearing a pinned end needs withMin(null)/withMax(null).
    /** @return list<int|float>|self */
    public function data(?array $data = null): array|self
    {
        return $data === null ? $this->data : $this->withData($data);
    }

    /** @return int|self */
    public function width(?int $w = null): int|self
    {
        return $w === null ? $this->width : $this->withWidth($w);
    }

    /** @return float|self|null */
    public function min(?float $m = null): float|self|null
    {
        return $m === null ? $this->min : $this->withMin($m);
    }

    /** @return float|self|null */
    public function max(?float $m = null): float|self|null
    {
        return $m === null ? $this->max : $this->withMax($m);
    }

    /** Short-form alias for {@see withStyle()}. */
    public function style(?Style $style): self   { return $this->withStyle($style); }
    /** Short-form alias for {@see withNoAutoMaxValue()}. */
    public function noAutoMaxValue(bool $disable = true): self { return $this->withNoAutoMaxValue($disable); }

    /**
     * Append a single sample. Mirrors ntcharts'
     * `Sparkline::Push(value)`. The window slide in {@see view()}
     * keeps only the last `width` points, so callers can append
     * indefinitely without trimming.
     *
     * WHY THIS COSTS O(n): the instance is immutable and `data` is a
     * public property holding the FULL recorded history (pinned by
     * SparklineTest), so every push materializes a fresh history copy.
     * Stream long sequences with {@see pushAll()} instead — one batched
     * append costs a single copy regardless of batch length — or keep a
     * plain PHP buffer and call withData() at render time.
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

    /**
     * Empty-data contract: with no samples renders a full row of spaces
     * (GLYPHS[0]) — width×1, not '' — see BarChart::view() for the ''
     * family and EmptyRenderContractTest for the pinned shapes.
     *
     * Family divergence: identical series can render slightly differently
     * here than in the column charts (baseline handling); unifying the
     * look was DECLINED as a behavior-breaking change (lib-reviews-1103).
     */
    /**
     * Honest rendered-frame size [w, h] of view() (sugar-charts-010).
     *
     * @return array{0: int, 1: int}
     */
    public function intrinsicSize(): array
    {
        return FrameSize::of($this->view());
    }

    public function view(): string
    {
        // CH-34 (sugar-charts-112): compose once, serve repeats from the
        // per-instance memo; mutate paths build new objects and start
        // empty. These renderers have no caller-owned canvas bridge.
        return ColorLadder::guard($this->memoizedRender(fn(): string => $this->composeView()));
    }

    private function composeView(): string
    {
        $prepared = $this->prepared();
        if ($prepared === null) {
            return '';
        }
        [$w, $points, $min, $range] = $prepared;
        if ($points === []) {
            return $this->styled(str_repeat(self::GLYPHS[0], $w));
        }

        return $this->renderBuffer($w, $points, $min, $range);
    }

    /**
     * The per-cell runes of the rendered row — one entry per display
     * column (empty string for a blank or a wide-glyph continuation),
     * which makes `implode('', $sparkline->cells())` equal to
     * `Ansi::strip($sparkline->view())` on every host. The structured
     * sibling of view() for consumers that need cells, not bytes:
     * re-parsing the ANSI output with preg_split becomes unnecessary
     * (sugar-charts-027).
     *
     * @return list<string>
     */
    public function cells(): array
    {
        $prepared = $this->prepared();
        if ($prepared === null) {
            return [];
        }
        [$w, $points, $min, $range] = $prepared;
        if ($points === []) {
            return array_fill(0, $w, self::GLYPHS[0]);
        }

        return array_map(
            static fn(Cell $c): string => $c->rune(),
            $this->assembleCells($w, $points, $min, $range)
        );
    }

    /**
     * Shared view()/cells() prep: window slide plus the sugar-charts-056
     * range resolution, byte-identical to the sequence view() used
     * inline before the cells() accessor landed. Null = the width-0
     * off-switch.
     *
     * @return null|array{0:int,1:list<mixed>,2:float,3:float}
     */
    private function prepared(): ?array
    {
        $w = $this->width;
        if ($w === 0) {
            return null;
        }
        $points = $this->data;
        // Slide window: keep the last $w points.
        if (count($points) > $w) {
            $points = array_slice($points, -$w);
        }
        if ($points === []) {
            return [$w, [], 0.0, 0.0];
        }

        // sugar-charts-056: the effective pair is resolved against the
        // scanned window BEFORE normalization — a lone pinned end that
        // excludes the data now throws instead of drawing a fake flat line.
        [$min, $max] = Range::resolve($this->min, $this->max, min($points), max($points), 'value');
        // A pinned max is used verbatim; with no pin the window's
        // max rescales. The autoMaxValue toggle cannot change either
        // outcome — with a pin it is ignored, without one the inner
        // guard computes max(points) against itself — so it is kept
        // purely for ntcharts WithNoAutoMaxValue API parity (its
        // docblock says so). Behaviour is byte-identical.
        return [$w, $points, (float) $min, $max - $min];
    }

    private function renderBuffer(int $w, array $points, float $min, float $range): string
    {
        return Buffer::fromGrid($w, 1, $this->assembleCells($w, $points, $min, $range))->toAnsi();
    }

    /**
     * sugar-charts-011: looping withCellAt() on the IMMUTABLE Buffer
     * cloned the whole grid per cell — O(width²) (~0.2s at w=6400,
     * never finishing at w=100000). Assemble the row-major cell list
     * in the loop and hand it to cellbuffer's documented bulk escape
     * hatch: one fromGrid(), O(width). The array mirrors the old
     * last-write-wins semantics exactly: a wide lead's continuation at
     * $col+1 is overwritten by the next point, as withCellAt did.
     *
     * @return list<Cell>
     */
    private function assembleCells(int $w, array $points, float $min, float $range): array
    {
        $bufferStyle = $this->style !== null ? BufferHelper::toBufferStyle($this->style) : null;
        $missing     = $w - count($points);
        $blank       = new Cell(self::GLYPHS[0], $bufferStyle, null, 1);
        $cells       = [];

        // Leading empty glyphs
        for ($i = 0; $i < $missing; $i++) {
            $cells[] = $blank;
        }

        // Data glyphs
        $col = $missing;
        foreach ($points as $v) {
            $g = self::glyph((float) $v, $min, $range);
            if ($g === self::GLYPHS[0] && $bufferStyle !== null) {
                // sugar-charts-106: a min-floor value rendered invisible
                // blank; with a style engaged the floor promotes to '▁'
                // so the wave's lowest point stays on screen. Unstyled
                // hosts keep the documented blank-ramp shape, and the
                // leading MISSING cells are unaffected either way.
                $g = self::GLYPHS[1];
            }
            $gw = BufferHelper::graphemeWidth($g);
            $cells[$col] = new Cell($g, $bufferStyle, null, $gw);
            // Wide-char continuation arm: the block glyphs are
            // East-Asian *Ambiguous*, so a wide-assumption host can
            // measure them as 2 cells — the arm is defensive, not dead.
            if ($gw === 2 && $col + 1 < $w) {
                $cells[$col + 1] = Cell::continuation();
            }
            $col++;
        }

        return $cells;
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
