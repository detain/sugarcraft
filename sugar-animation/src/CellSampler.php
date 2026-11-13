<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

/**
 * Incremental cell-grid sampler for one compositing walk (SA-D3 -013).
 *
 * The walk used to re-download the ENTIRE screen into the cell grid for
 * every emitted frame: a 4000×4000-pixel canvas reduced to a 40×40 grid,
 * one `imagecolorat()` per source pixel per frame, even when the frame
 * painted a 4×4 corner. For a typical animation that is the same
 * megapixel sweep repeated MAX_FRAMES times, and it is pure waste — a
 * frame's canvas writes are confined to two known rectangles: the
 * DISPOSAL_BACKGROUND clear of the previous frame and the frame's own
 * composited rect (a DISPOSAL_PREVIOUS restore is the full-screen case).
 *
 * This sampler owns the persistent grid. Edges are precomputed once (the
 * window arithmetic was previously re-derived with four integer divisions
 * per cell per frame), and a rect resample touches only the cells whose
 * sampling window intersects the dirty rect. Cells outside are carried —
 * the canvas pixels under them did not move, so the colours previously
 * averaged from them are exactly the colours a full sweep would produce.
 * Correctness therefore rests on one invariant, held by the walk:
 * `resampleRect` is called with a superset of every canvas pixel written
 * since the last yield.
 *
 * Emitted frames share row structs with the sampler; a later resample
 * REPLACES a dirty row with a fresh array rather than writing into the
 * shared one, so already-emitted frames keep their bytes by PHP's
 * copy-on-write value semantics.
 *
 * @internal The grid is a walk implementation detail — {@see Decoder} owns
 *           the only production caller. Public so tests can drive it.
 */
final class CellSampler
{
    /** @var list<list<int>> packed cell colour (0xRRGGBB), -1 = terminal background */
    private array $packed;

    /** @var list<list<array{int,int,int}|null>> row structs handed to frames; rebuilt per dirty row only */
    private array $structs;

    /** @var list<int> first canvas column of each cell's sampling window */
    private readonly array $colX0;

    /** @var list<int> last canvas column (inclusive) of each cell's sampling window */
    private readonly array $colX1;

    /** @var list<int> first canvas row of each cell's sampling window */
    private readonly array $rowY0;

    /** @var list<int> last canvas row (inclusive) of each cell's sampling window */
    private readonly array $rowY1;

    public function __construct(
        private readonly int $screenW,
        private readonly int $screenH,
        private readonly int $cellsW,
        private readonly int $cellsH,
    ) {
        $colX0 = $colX1 = [];
        for ($cx = 0; $cx < $cellsW; $cx++) {
            $x0 = intdiv($cx * $screenW, $cellsW);
            $colX0[] = $x0;
            $colX1[] = max($x0, intdiv(($cx + 1) * $screenW, $cellsW) - 1);
        }
        $rowY0 = $rowY1 = [];
        for ($cy = 0; $cy < $cellsH; $cy++) {
            $y0 = intdiv($cy * $screenH, $cellsH);
            $rowY0[] = $y0;
            $rowY1[] = max($y0, intdiv(($cy + 1) * $screenH, $cellsH) - 1);
        }
        $this->colX0 = $colX0;
        $this->colX1 = $colX1;
        $this->rowY0 = $rowY0;
        $this->rowY1 = $rowY1;

        $emptyRow = array_fill(0, $cellsW, -1);
        $this->packed = array_fill(0, $cellsH, $emptyRow);
        $structRow = array_fill(0, $cellsW, null);
        $this->structs = array_fill(0, $cellsH, $structRow);
    }

    /**
     * Sample every cell from the canvas — the walk's first frame, and any
     * moment a full-screen write (DISPOSAL_PREVIOUS restore) has moved
     * pixels the grid has not seen.
     */
    public function resampleAll(\GdImage $canvas): void
    {
        for ($cy = 0; $cy < $this->cellsH; $cy++) {
            $y0 = $this->rowY0[$cy];
            $y1 = $this->rowY1[$cy];
            for ($cx = 0; $cx < $this->cellsW; $cx++) {
                $this->packed[$cy][$cx] = $this->sampleCell($canvas, $this->colX0[$cx], $this->colX1[$cx], $y0, $y1);
            }
            $this->structs[$cy] = $this->rowStruct($cy);
        }
    }

    /**
     * Re-sample only the cells whose window intersects the inclusive canvas
     * rect [$rx0,$ry0]–[$rx1,$ry1]. The rect must be a superset of the
     * pixels written since the last call — cells outside are carried
     * untouched, so an under-inclusive rect would emit stale colours; an
     * over-inclusive one only costs work, never correctness.
     */
    public function resampleRect(\GdImage $canvas, int $rx0, int $ry0, int $rx1, int $ry1): void
    {
        if ($rx0 < 0 || $ry0 < 0 || $rx1 >= $this->screenW || $ry1 >= $this->screenH || $rx0 > $rx1 || $ry0 > $ry1) {
            throw new \InvalidArgumentException(
                "CellSampler: rect [{$rx0},{$ry0}]..[{$rx1},{$ry1}] outside the {$this->screenW}x{$this->screenH} canvas"
            );
        }

        for ($cy = 0; $cy < $this->cellsH; $cy++) {
            if ($this->rowY1[$cy] < $ry0 || $this->rowY0[$cy] > $ry1) {
                continue; // window entirely above/below the rect — carry it
            }
            $y0 = $this->rowY0[$cy];
            $y1 = $this->rowY1[$cy];
            for ($cx = 0; $cx < $this->cellsW; $cx++) {
                if ($this->colX1[$cx] < $rx0 || $this->colX0[$cx] > $rx1) {
                    continue; // window entirely left/right of the rect — carry it
                }
                $this->packed[$cy][$cx] = $this->sampleCell($canvas, $this->colX0[$cx], $this->colX1[$cx], $y0, $y1);
            }
            $this->structs[$cy] = $this->rowStruct($cy);
        }
    }

    /**
     * The grid in {@see Frame} shape. The array is value-shared with the
     * sampler; dirty rows are replaced wholesale, never written in place,
     * so holders of an earlier snapshot keep their bytes.
     *
     * @return list<list<array{int,int,int}|null>>
     */
    public function cells(): array
    {
        return $this->structs;
    }

    /**
     * Area-average one cell window to a packed 0xRRGGBB, or -1 when no
     * opaque pixel landed in it. Byte-identical math to the old inline
     * sampleCanvas sweep: same summation order, same per-channel rounding.
     */
    private function sampleCell(\GdImage $canvas, int $x0, int $x1, int $y0, int $y1): int
    {
        $sumR = 0;
        $sumG = 0;
        $sumB = 0;
        $count = 0;
        for ($sy = $y0; $sy <= $y1; $sy++) {
            for ($sx = $x0; $sx <= $x1; $sx++) {
                $pixel = imagecolorat($canvas, $sx, $sy);
                // Truecolor with alpha saves pixels as packed ARGB: alpha 0 is
                // opaque, alpha 127 fully transparent — anything non-zero is
                // excluded from the average.
                if ((($pixel >> 24) & 0xFF) !== 0) {
                    continue;
                }
                $sumR += ($pixel >> 16) & 0xFF;
                $sumG += ($pixel >> 8) & 0xFF;
                $sumB += $pixel & 0xFF;
                $count++;
            }
        }
        if ($count === 0) {
            return -1; // no opaque pixel sampled — the cell shows the terminal background
        }

        return ((int) round($sumR / $count) << 16)
            | ((int) round($sumG / $count) << 8)
            | (int) round($sumB / $count);
    }

    /**
     * Rebuild one row's {@see Frame} structs from its packed colours.
     *
     * @return list<array{int,int,int}|null>
     */
    private function rowStruct(int $cy): array
    {
        $row = [];
        foreach ($this->packed[$cy] as $cell) {
            $row[] = $cell < 0
                ? null
                : [($cell >> 16) & 0xFF, ($cell >> 8) & 0xFF, $cell & 0xFF];
        }

        return $row;
    }
}
