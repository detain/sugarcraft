<?php

declare(strict_types=1);

namespace SugarCraft\Charts\LineChart;

use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Canvas\Graph;
use SugarCraft\Charts\Support\Finite;
use SugarCraft\Charts\Support\FrameSize;
use SugarCraft\Charts\Support\Range;

/**
 * Waveline (XY-point) LineChart variant. Mirrors ntcharts'
 * `linechart/waveline` — accepts arbitrary `[x, y]` points (not just
 * a 1D series), maps them onto the configured X/Y range, and draws
 * connecting strokes between consecutive points.
 *
 * Useful for plotting parametric curves (Lissajous figures, phase
 * portraits, sampled audio) where each sample carries an explicit X
 * coordinate rather than an index.
 *
 * ```php
 * $points = [];
 * for ($t = 0; $t < 100; $t++) {
 *     $points[] = [$t * 0.1, sin($t * 0.1) * 5];
 * }
 * echo Waveline::new($points, 40, 8)->view();
 * ```
 */
final class Waveline
{
    /** @param list<array{0:int|float,1:int|float}> $points */
    private function __construct(
        public readonly array $points,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $xMin,
        public readonly ?float $xMax,
        public readonly ?float $yMin,
        public readonly ?float $yMax,
        public readonly string $point,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('waveline.dim_nonneg'));
        }
        // sugar-charts-042: the whole-list Finite sweep moved to the
        // ingestion doors (new / withPoints / push / pushAll) — re-sweeping
        // every EXISTING point on each rebuild made push() O(N). Every
        // list reaching this constructor has passed a door that validated
        // what it added; callers never touch the property directly.
        // explicit ranges must be ordered; null ends are "auto".
        Range::pin($xMin, $xMax, 'X');
        Range::pin($yMin, $yMax, 'Y');
    }

    /**
     * Validate one incoming point and return it (raw when already typed,
     * floats when numeric strings — no downstream strict call ever sees
     * string contamination). Throws the ingestion family naming the index
     * for a bad shape or a non-finite coordinate.
     *
     * @param array<array-key, mixed> $p
     * @return array{0:int|float,1:int|float}
     */
    private static function ingested(mixed $p, int $index): array
    {
        if (!is_array($p) || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1])) {
            throw new \InvalidArgumentException(Lang::t('waveline.point_shape', [
                'index' => (string) $index,
                'given' => get_debug_type($p),
            ]));
        }
        if (!is_finite((float) $p[0]) || !is_finite((float) $p[1])) {
            throw new \InvalidArgumentException(Lang::t('waveline.point_finite', [
                'index' => (string) $index,
            ]));
        }
        if ((is_int($p[0]) || is_float($p[0])) && (is_int($p[1]) || is_float($p[1]))) {
            return $p;
        }
        return [(float) $p[0], (float) $p[1]];
    }

    /**
     * @param iterable<mixed> $points
     * @return list<array{0:int|float,1:int|float}>
     */
    private static function ingestAll(iterable $points): array
    {
        $out   = [];
        $index = 0;
        foreach ($points as $p) {
            $out[] = self::ingested($p, $index++);
        }
        return $out;
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public static function new(array $points = [], int $width = 40, int $height = 8): self
    {
        return new self(self::ingestAll($points), $width, $height, null, null, null, null, '*');
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public function withPoints(array $points): self
    {
        return new self(self::ingestAll($points), $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    /**
     * Append one point.
     *
     * Building by repeated push() is O(n²) (each call clones state) — batch with pushAll().
     */
    public function push(int|float $x, int|float $y): self
    {
        // The door validates what it adds — the ctor sweep it used to
        // ride is gone (sugar-charts-042), so this stays O(1) per call.
        Finite::assert($x);
        Finite::assert($y);
        return new self([...$this->points, [$x, $y]], $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    /**
     * Append several `[x, y]` points at once.
     *
     * @param iterable<array{0:int|float,1:int|float}> $points
     */
    public function pushAll(iterable $points): self
    {
        // sugar-charts-042: one accumulation, one validation sweep, one
        // construction — the old foreach-over-push was O(P × (N+P)) with
        // full re-validation per element. A bad batch element throws the
        // ingestion family naming its index.
        $added = self::ingestAll($points);
        if ($added === []) {
            return $this;
        }
        return new self([...$this->points, ...$added], $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    /** Reset the point buffer while keeping size / range / glyph. */
    public function clear(): self
    {
        return new self([], $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    public function count(): int
    {
        return count($this->points);
    }

    /** Combined `[xMin, xMax, yMin, yMax]` shorthand. */
    public function withXYRange(?float $xMin, ?float $xMax, ?float $yMin, ?float $yMax): self
    {
        return new self($this->points, $this->width, $this->height, $xMin, $xMax, $yMin, $yMax, $this->point);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException(Lang::t('waveline.dim_nonneg'));
        }
        return new self($this->points, $w, $h, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    public function withXRange(?float $min, ?float $max): self
    {
        return new self($this->points, $this->width, $this->height, $min, $max, $this->yMin, $this->yMax, $this->point);
    }

    public function withYRange(?float $min, ?float $max): self
    {
        return new self($this->points, $this->width, $this->height, $this->xMin, $this->xMax, $min, $max, $this->point);
    }

    public function withPoint(string $r): self
    {
        return new self($this->points, $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $r);
    }

    // Short-form aliases.
    /** @param list<array{0:int|float,1:int|float}> $points */
    public function points(array $points): self   { return $this->withPoints($points); }
    /** Short-form alias for {@see withXYRange()} — `xyRange` mirrors LineChart. */
    public function xyRange(?float $xMin, ?float $xMax, ?float $yMin, ?float $yMax): self {
        return $this->withXYRange($xMin, $xMax, $yMin, $yMax);
    }
    /** Short-form alias for {@see withSize()}. */
    public function size(int $w, int $h): self    { return $this->withSize($w, $h); }
    /** Short-form alias for {@see withXRange()}. */
    public function xRange(?float $min, ?float $max): self { return $this->withXRange($min, $max); }
    /** Short-form alias for {@see withYRange()}. */
    public function yRange(?float $min, ?float $max): self { return $this->withYRange($min, $max); }
    /** Short-form alias for {@see withPoint()}. */
    public function point(string $r): self        { return $this->withPoint($r); }

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
        if ($this->width < 1 || $this->height < 1) {
            return '';
        }
        if ($this->points === []) {
            return (new Canvas($this->width, $this->height))->view();
        }
        $xs = array_map(static fn(array $p): float => (float) $p[0], $this->points);
        $ys = array_map(static fn(array $p): float => (float) $p[1], $this->points);

        [$xMin, $xMax] = Range::resolve($this->xMin, $this->xMax, min($xs), max($xs), 'X');
        [$yMin, $yMax] = Range::resolve($this->yMin, $this->yMax, min($ys), max($ys), 'Y');
        if ($xMax == $xMin) { $xMax = $xMin + 1.0; }
        if ($yMax == $yMin) { $yMax = $yMin + 1.0; }

        $canvas = new Canvas($this->width, $this->height);

        $project = function (array $p) use ($xMin, $xMax, $yMin, $yMax): array {
            [$x, $y] = $p;
            $tx = ((float) $x - $xMin) / ($xMax - $xMin);
            $ty = ((float) $y - $yMin) / ($yMax - $yMin);
            $tx = max(0.0, min(1.0, $tx));
            $ty = max(0.0, min(1.0, $ty));
            return [
                (int) round($tx * ($this->width  - 1)),
                (int) round((1.0 - $ty) * ($this->height - 1)),
            ];
        };

        $coords = array_map($project, $this->points);
        $count  = count($coords);
        for ($i = 0; $i < $count; $i++) {
            [$x, $y] = $coords[$i];
            $canvas->setCell($x, $y, $this->point);
            if ($i + 1 < $count) {
                [$x2, $y2] = $coords[$i + 1];
                self::drawConnector($canvas, $x, $y, $x2, $y2);
            }
        }
        return $canvas->view();
    }

    public function __toString(): string { return $this->view(); }

    /** Bresenham-ish straight-line connector — picks slope-aware glyphs. */
    private static function drawConnector(Canvas $c, int $x1, int $y1, int $x2, int $y2): void
    {
        $rune = self::connectorRune($x1, $y1, $x2, $y2);
        Graph::drawLine($c, $x1, $y1, $x2, $y2, $rune);
    }

    /** Determine the appropriate line rune based on slope direction. */
    private static function connectorRune(int $x1, int $y1, int $x2, int $y2): string
    {
        return match (true) {
            $y1 === $y2 => '─',
            $x1 === $x2 => '│',
            ($x2 > $x1 && $y2 < $y1) || ($x2 < $x1 && $y2 > $y1) => '╱',
            default => '╲',
        };
    }
}
