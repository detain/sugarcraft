<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use RuntimeException;

/**
 * The dock band's geometry as the model needs to know it.
 *
 * TUIARCH-02 phase 1: the model read the renderer's published frame static
 * (TuiRenderer::lastDockFrame()) directly at five gesture sites, each
 * indexing raw array keys and each null-guarding the whole. This value
 * object is the single typed adapter: App::dockGeometry() funnels every
 * model-side read through it, so phase 2 — the retirement that landed since
 * — swapped one producer and touched no reader. The static is gone; the
 * paint step adopts the frame onto the model itself
 * (App::adoptPaintedDockFrame()), and this adapter now reads
 * App::$paintedDockFrame.
 *
 * The fields mirror the published frame exactly. bandTop/bandCols/paneRows/
 * centerFrom/centerTo are PAINT measurements only the renderer can state
 * today; agentCols and cols ride the band math the renderer also derives
 * live in dockBandMeasure(), and the parity test pins the two derivations
 * against the frame so the VO cannot drift from what was painted.
 */
final class DockGeometry
{
    /**
     * @param int $cols      the frame's full column width the band was split from
     * @param int $bandTop   the row the band starts painting at
     * @param int $bandCols  the band's width after the live-agent split is carved out
     * @param int $paneRows  the rows the band has for panes
     * @param int $centerFrom the first centre column, band-relative
     * @param int $centerTo  the last centre column, band-relative
     * @param int $agentCols the live-agent column's width (0 when the split is off)
     */
    private function __construct(
        public readonly int $cols,
        public readonly int $bandTop,
        public readonly int $bandCols,
        public readonly int $paneRows,
        public readonly int $centerFrom,
        public readonly int $centerTo,
        public readonly int $agentCols,
    ) {}

    /**
     * Frame fields as an already-measured list (test fixtures and future
     * model-side derivation feed this; key order is the frame's own).
     */
    public static function new(int $cols, int $bandTop, int $bandCols, int $paneRows, int $centerFrom, int $centerTo, int $agentCols): self
    {
        return new self($cols, $bandTop, $bandCols, $paneRows, $centerFrom, $centerTo, $agentCols);
    }

    /**
     * Adapt a published frame (or null for "no measured frame painted yet").
     *
     * A frame that is present but missing a key is a renderer defect, not a
     * runtime state to model: it fails loudly naming the key rather than
     * letting a gesture act on a half-known band.
     */
    public static function fromFrame(?array $frame): ?self
    {
        if ($frame === null) {
            return null;
        }

        foreach (['cols', 'bandTop', 'bandCols', 'paneRows', 'centerFrom', 'centerTo', 'agentCols'] as $key) {
            if (!isset($frame[$key])) {
                throw new RuntimeException('Dock frame is missing the ' . $key . ' field the dock gestures read.');
            }
        }

        return new self(
            (int) $frame['cols'],
            (int) $frame['bandTop'],
            (int) $frame['bandCols'],
            (int) $frame['paneRows'],
            (int) $frame['centerFrom'],
            (int) $frame['centerTo'],
            (int) $frame['agentCols'],
        );
    }
}
