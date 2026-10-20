<?php

declare(strict_types=1);

namespace SugarCraft\Image\Render;

use InvalidArgumentException;

/**
 * D-02a: unit-aligned band partition — splits a pixel/cell row range into
 * [startRow, endRow) half-open band specs, the shape a later per-band worker
 * pool (S7.6) or incremental pump consumes. Pure utility: it owns no render
 * state and no renderer calls it yet, so landing it cannot move a single
 * output byte (the DoD of this step).
 *
 * Mirrors chafa's chafa_process_batches() band loop
 * (chafa/internal/chafa-batch.c:113-139), transcribed call-for-call with two
 * deliberate deviations, each pinned by tests:
 *
 *  1. Boundaries are computed with integer arithmetic — the i-th cut lands at
 *     unit boundary `intdiv($i * $units, $bandCount)` — where chafa
 *     accumulates a gfloat `units_per_batch` and truncates per step
 *     (chafa-batch.c:98,119-124). The two are the same SHAPE (same band
 *     count, unit alignment, short final band) but not always the same cut:
 *     a binary-inexact units_per_batch makes chafa's running sum lag the
 *     exact division by one unit (12 bands over 64 units crosses 31.999…
 *     where intdiv says 32). The exact-integer cut is deliberate — the
 *     boundary contract is pinned by the BandsTest invariants, not by chafa
 *     bit-parity, since chafa's band count comes from its thread-count
 *     ladder, which this stage does not reproduce (an S7.6 caller supplies
 *     $maxBands).
 *  2. Chafa's empty-band truncation (`n_batches = i`, chafa-batch.c:134-139)
 *     is structurally impossible here: the band count is clamped to the unit
 *     count up front, so every emitted band carries at least one unit and
 *     stitches contiguously. The clamp keeps the "band count never exceeds
 *     the work" property chafa's truncation leg pins.
 *
 * The cost gate below implements chafa's refusal pattern
 * (chafa/internal/chafa-pixops.c:540 — palette building parallelizes only at
 * `n_bins >= 512`): a row range too short to pay for per-band fixed costs is
 * partitioned into exactly ONE band, and chafa's caller-side rule
 * (chafa-batch.c:153-159: with fewer than two threads the work runs inline in
 * the caller) becomes simply `count($bands) === 1` for every consumer.
 */
final class Bands
{
    /**
     * Rows per sixel band — DECSIXEL graphic symbols accumulate six pixel
     * rows before a newline can be emitted. Per chafa
     * chafa/internal/chafa-sixel-renderer.c:31 `SIXEL_CELL_HEIGHT 6`; the
     * live emit loop in sugar-image groups the same 6 rows
     * (Renderer/SixelRenderer.php, `count($rowsInBand) === 6`).
     */
    public const SIXEL_UNIT = 6;

    /**
     * Row count below which the partition collapses to a single band: at
     * this size the per-band fixed costs (color-set re-emit, worker spawn)
     * outweigh anything the split can save. Per the cost-gate decision in
     * plan_chafa.md S5.2 (survey D-02a free-win 2, `h < 64 ? 1 band`).
     */
    public const COST_GATE_ROWS = 64;

    private function __construct()
    {
    }

    /**
     * Partition rows `[0, $totalRows)` into unit-aligned bands.
     *
     * Invariants, each pinned by BandsTest:
     *  - bands are half-open `[startRow, endRow)`, non-empty, and stitch
     *    contiguously: first starts at 0, each end is the next start, the
     *    last ends exactly at `$totalRows` (band-split equality — the whole
     *    range is covered once and only once);
     *  - every band except the last spans a multiple of `$unit` rows; only
     *    the final band may be short (the remainder, chafa-batch.c:129-131);
     *  - the band count is at most `min($maxBands, ceil($totalRows / $unit))`
     *    and never exceeds the row count;
     *  - below {@see self::COST_GATE_ROWS} rows the result is always the
     *    single covering band `[0, $totalRows)`, whatever `$maxBands` asks.
     *
     * A cell-based caller passes the whole cell height as `$unit` so bands
     * hold entire cells; a sixel caller passes {@see self::SIXEL_UNIT} (or
     * nothing — it is the default).
     *
     * @param int          $totalRows    rows to cover, [0, $totalRows); 0 yields no bands
     * @param positive-int $unit         rows per indivisible group (6 for sixel)
     * @param positive-int $maxBands     desired parallelism ceiling; 1 keeps the single covering band
     *
     * @return list<array{int, int}>     non-empty bands in row order; [] when $totalRows is 0
     *
     * @throws InvalidArgumentException on a negative row count or a non-positive $unit / $maxBands
     */
    public static function partition(int $totalRows, int $unit = self::SIXEL_UNIT, int $maxBands = 1): array
    {
        if ($totalRows < 0) {
            throw new InvalidArgumentException("band partition needs a non-negative row count, got {$totalRows}");
        }
        if ($unit < 1) {
            throw new InvalidArgumentException("band unit must be at least one row, got {$unit}");
        }
        if ($maxBands < 1) {
            throw new InvalidArgumentException("band count ceiling must be at least one, got {$maxBands}");
        }

        // A zero-row canvas carries no work: no bands at all, not even an
        // empty one (chafa-batch.c:92-93 `if (n_rows < 1) return;`).
        if ($totalRows === 0) {
            return [];
        }

        // Cost gate and single-band ask both collapse to the inline shape —
        // consumers key on count($bands) === 1, never on which door fired.
        if ($totalRows < self::COST_GATE_ROWS || $maxBands === 1) {
            return [[0, $totalRows]];
        }

        $units = intdiv($totalRows + $unit - 1, $unit);
        $bandCount = min($maxBands, $units);

        $bands = [];
        $startRow = 0;
        for ($edge = 1; $edge <= $bandCount; $edge++) {
            $endRow = min(intdiv($edge * $units, $bandCount) * $unit, $totalRows);
            $bands[] = [$startRow, $endRow];
            $startRow = $endRow;
        }

        return $bands;
    }
}
