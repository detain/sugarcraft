<?php

declare(strict_types=1);

/*

 * This file is part of the sugarcraft/sugar-charts package.
 *
 * (c) Joe Huss <detain@interserver.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * and NOTICE files that were distributed with this source code.
 */

namespace SugarCraft\Charts\Support;

use SugarCraft\Style\Style;

/**
 * The write-side contract of a character cell grid: the surface renderers
 * paint into, declared so `withCanvas()` can accept any grid implementation
 * (sugar-charts-007 — sugar-charts' own Canvas, a test double, or a host
 * application's grid) instead of being an inert mode flag.
 *
 * Antecedent: lib-local (no upstream mirror; the ntcharts renderer surface
 * offers no grid-injection point).
 *
 * Writes outside the grid's own bounds are the canvas's business: this
 * contract guarantees nothing about off-grid cells, mirroring Canvas's
 * clamp-and-drop behavior.
 */
interface CellGrid
{
    /**
     * Paint one cell. A rune wider than one cell is the canvas's call —
     * Canvas collapses it to a single cell per its documented clamp.
     */
    public function setCell(int $x, int $y, string $rune, ?Style $style = null): void;

    /**
     * Paint a string horizontally from ($x, $y), one grapheme cluster per
     * cell, advancing by each cluster's display width.
     */
    public function setString(int $x, int $y, string $text, ?Style $style = null): void;

    /** The canvas width in cells. Charts consult it when they size a render into a foreign grid. */
    public function width(): int;

    /** The canvas height in cells. */
    public function height(): int;

    /** The rendered view: rows joined by newlines, exactly as a chart's view() would emit. */
    public function view(): string;
}
