<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Canvas;

use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Charts\Lang;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Fixed-size 2D grid of {@see Cell}s. Charts draw onto the canvas and
 * call {@see view()} to produce a ready-to-print frame. Coordinates are
 * 0-based: $x is column, $y is row, with (0, 0) at the top-left.
 *
 * The canvas is mutable in place — cheaper for hot rendering paths than
 * building a new grid per draw call.
 */
final class Canvas implements \SugarCraft\Charts\Support\CellGrid
{
    /** @var array<int, array<int, Cell>> [row][col] */
    private array $cells;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException(Lang::t('canvas.dim_positive'));
        }
        $this->clear();
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * Write a single rune into cell ($x, $y). Out-of-range
     * coordinates are silently dropped (no exception).
     *
     * the rune is measured with the shared sugar-core display
     * width. A wide (CJK / emoji) rune claims two columns — the next one
     * receives a width-0 continuation cell — and a zero-width cluster
     * merges into the base rune it decorates (the cell at $x, or the
     * anchor one column back when $x is a continuation half); with no
     * base to decorate it is dropped. Ordinary single-cell runes behave
     * exactly as before.
     *
     * @return void
     */
    public function setCell(int $x, int $y, string $rune, ?Style $style = null): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $w = $rune === '' ? 1 : Width::of($rune);
        if ($w === 0) {
            // Zero-width cluster: merge into the cell it decorates — the
            // cell at $x when it already holds a base rune, otherwise the
            // anchor one column back (when $x sits on a wide rune's
            // continuation half). With no base to decorate there is
            // nowhere to attach the mark, so it is dropped.
            $anchorX = $x;
            $anchor = $this->cells[$y][$x] ?? null;
            if (($anchor === null || $anchor->width === 0) && $x > 0) {
                $prev = $this->cells[$y][$x - 1] ?? null;
                if ($prev !== null && $prev->width !== 0) {
                    $anchorX = $x - 1;
                    $anchor = $prev;
                }
            }
            if ($anchor === null || $anchor->width === 0) {
                return;
            }
            $this->cells[$y][$anchorX] = new Cell($anchor->rune . $rune, $anchor->style, $anchor->width);
            return;
        }
        $this->placeCell($x, $y, $rune, $style, $w >= 2 ? 2 : 1);
    }

    /**
     * Write a base cell and keep the wide/continuation bookkeeping
     * consistent: a wide rune claims its right half, and any write that
     * steals a half (or replaces a wide rune with a narrow one) retires
     * the now-stale partner instead of leaving a ghost continuation cell
     * behind.
     *
     * @return void
     */
    private function placeCell(int $x, int $y, string $rune, ?Style $style, int $w): void
    {
        $existing = $this->cells[$y][$x] ?? null;
        if ($existing !== null && $existing->width === 0 && $x > 0) {
            // Overwriting the right half of a wide rune: its base can no
            // longer be displayed in full, so the anchor goes with it.
            $anchor = $this->cells[$y][$x - 1] ?? null;
            if ($anchor !== null && $anchor->width >= 2) {
                unset($this->cells[$y][$x - 1]);
            }
        }
        $this->cells[$y][$x] = new Cell($rune, $style, $w);
        if ($w >= 2) {
            if ($x + 1 < $this->width) {
                $this->cells[$y][$x + 1] = new Cell('', null, 0);
            }
            return;
        }
        if ($existing !== null && $existing->width >= 2 && $x + 1 < $this->width) {
            $next = $this->cells[$y][$x + 1] ?? null;
            if ($next !== null && $next->width === 0) {
                unset($this->cells[$y][$x + 1]);
            }
        }
    }

    /**
     * Read the {@see Cell} at ($x, $y). Out-of-range coordinates
     * return an empty placeholder Cell.
     *
     * @return Cell
     *
     * @deprecated Use {@see self::cell()} — the house law is bare
     *             accessors (no `get` prefix).
     */
    public function getCell(int $x, int $y): Cell
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return new Cell();
        }
        return $this->cells[$y][$x] ?? new Cell();
    }

    /**
     * Bare-name accessor for {@see self::getCell()} — the house law is
     * accessors without a `get` prefix.
     */
    public function cell(int $x, int $y): Cell
    {
        return $this->getCell($x, $y);
    }

    /**
     * Replace a cell's style without changing its rune. Mirrors
     * ntcharts' `SetCellStyle`. Out-of-range coordinates are no-ops.
     *
     * @return void
     */
    public function setCellStyle(int $x, int $y, ?Style $style): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $existing = $this->cells[$y][$x] ?? new Cell();
        $this->cells[$y][$x] = new Cell($existing->rune, $style, $existing->width);
    }

    /**
     * Read a cell's style (or null when unset). Mirrors ntcharts'
     * `GetCellStyle`.
     *
     * @deprecated Use {@see self::cellStyle()} — the house law is bare
     *             accessors (no `get` prefix).
     */
    public function getCellStyle(int $x, int $y): ?Style
    {
        return $this->getCell($x, $y)->style;
    }

    /**
     * Bare-name accessor for {@see self::getCellStyle()} — the house
     * law is accessors without a `get` prefix.
     */
    public function cellStyle(int $x, int $y): ?Style
    {
        return $this->getCell($x, $y)->style;
    }

    /**
     * Place a horizontal run of `$cells` cells starting at `($x, $y)`,
     * each carrying `$rune` (and optional shared `$style`). Mirrors
     * ntcharts' `SetRunes` when used per-row. Cells that fall outside
     * the canvas bounds are silently dropped.
     *
     * Advancement is by each rune's DISPLAY width, so a wide
     * rune consumes two columns instead of nudging the rest of the row
     * one cell to the left of where the terminal will paint it. A
     * zero-width mark is MERGED into the cell just written (below 8.4
     * `setString` falls back to codepoint splitting, and dropping the
     * lone combining codepoint would silently lose the accent).
     *
     * @param iterable<string> $runes
     * @return void
     */
    public function setRunes(int $x, int $y, iterable $runes, ?Style $style = null): void
    {
        $col = $x;
        $prevCol = null;
        foreach ($runes as $r) {
            $w = $r === '' ? 1 : Width::of($r);
            if ($w === 0) {
                if ($prevCol !== null && isset($this->cells[$y][$prevCol])) {
                    $existing = $this->cells[$y][$prevCol];
                    $this->cells[$y][$prevCol] = new Cell($existing->rune . $r, $existing->style, $existing->width);
                }
                continue;
            }
            $this->setCell($col, $y, $r, $style);
            $prevCol = $col;
            $col += $w >= 2 ? 2 : 1;
        }
    }

    /**
     * Place a string of glyphs along row `$y` starting at column `$x`.
     * Split into display clusters via {@see BufferHelper::clusters()}
     * (ICU grapheme boundaries through the hard ext-intl requirement;
     * codepoint arms only guard malformed input).
     * Mirrors ntcharts' `SetString`.
     *
     * @return void
     */
    public function setString(int $x, int $y, string $s, ?Style $style = null): void
    {
        $this->setRunes($x, $y, BufferHelper::clusters($s), $style);
    }

    /**
     * Place pre-styled lines at `($x, $y)` — each line goes on its own
     * row. Mirrors ntcharts' `SetLines`. Each line is split through
     * {@see setString()} so the same multibyte handling applies.
     *
     * @param iterable<string> $lines
     * @return void
     */
    public function setLines(int $x, int $y, iterable $lines, ?Style $style = null): void
    {
        $row = 0;
        foreach ($lines as $line) {
            $this->setString($x, $y + $row, $line, $style);
            $row++;
        }
    }

    /**
     * Fill every cell in the rectangle `($x0..$x1, $y0..$y1)` (inclusive)
     * with `$rune` (and optional `$style`). Out-of-range coordinates
     * are clamped. Mirrors ntcharts' `Fill`.
     *
     * @return void
     */
    public function fill(int $x0, int $y0, int $x1, int $y1, string $rune, ?Style $style = null): void
    {
        if ($x0 > $x1) { [$x0, $x1] = [$x1, $x0]; }
        if ($y0 > $y1) { [$y0, $y1] = [$y1, $y0]; }
        $x0 = max(0, $x0);
        $y0 = max(0, $y0);
        $x1 = min($this->width  - 1, $x1);
        $y1 = min($this->height - 1, $y1);
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $this->placeCell($x, $y, $rune, $style, $rune === '' ? 1 : (Width::of($rune) >= 2 ? 2 : 1));
            }
        }
    }

    /**
     * Fill an entire row with `$rune`. Mirrors ntcharts' `FillLine`.
     * Out-of-range rows are silently ignored.
     *
     * @return void
     */
    public function fillLine(int $y, string $rune, ?Style $style = null): void
    {
        if ($y < 0 || $y >= $this->height) {
            return;
        }
        $this->fill(0, $y, $this->width - 1, $y, $rune, $style);
    }

    /**
     * Shift every cell down by `$n` rows. Cells that fall off the bottom
     * are dropped; new rows at the top fill with empty cells. Mirrors
     * ntcharts' `ShiftDown`.
     *
     * @return void
     */
    public function shiftDown(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = $this->height - 1; $y >= 0; $y--) {
            $this->cells[$y] = $y - $n >= 0 ? ($this->cells[$y - $n] ?? []) : [];
        }
    }

    /**
     * Shift every cell up by `$n` rows. Mirrors ntcharts' `ShiftUp`.
     * The bottom rows fill with empty cells.
     *
     * @return void
     */
    public function shiftUp(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = 0; $y < $this->height; $y++) {
            $this->cells[$y] = $y + $n < $this->height ? ($this->cells[$y + $n] ?? []) : [];
        }
    }

    /**
     * Shift every row left by `$n` columns. Cells that fall off the
     * left are dropped; the right edge fills with empty cells.
     * Mirrors ntcharts' `ShiftLeft`.
     *
     * @return void
     */
    public function shiftLeft(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = 0; $y < $this->height; $y++) {
            $newRow = [];
            foreach ($this->cells[$y] ?? [] as $x => $cell) {
                $nx = $x - $n;
                if ($nx >= 0 && $nx < $this->width) {
                    $newRow[$nx] = $cell;
                }
            }
            $this->cells[$y] = $newRow;
        }
    }

    /**
     * Shift every row right by `$n` columns. Cells that fall off the
     * right are dropped; the left edge fills with empty cells.
     * Mirrors ntcharts' `ShiftRight`.
     *
     * @return void
     */
    public function shiftRight(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = 0; $y < $this->height; $y++) {
            $newRow = [];
            foreach ($this->cells[$y] ?? [] as $x => $cell) {
                $nx = $x + $n;
                if ($nx >= 0 && $nx < $this->width) {
                    $newRow[$nx] = $cell;
                }
            }
            $this->cells[$y] = $newRow;
        }
    }

    /**
     * Reset every cell to empty. Mirrors ntcharts' `Clear`.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->cells = [];
        for ($y = 0; $y < $this->height; $y++) {
            $this->cells[$y] = [];
        }
    }

    /**
     * Render the canvas as a string. Empty cells render as spaces; each
     * row is right-trimmed, and rows join with "\n". Adjacent cells that
     * share a style emit its escape sequence once for the whole run, and
     * exactly one reset closes each styled run — the frame carries no
     * per-cell reset spam.
     *
     * Rows assume the host cursor is at column 0 (plain "\n" joins, no
     * absolute positioning). Hosts that must paint at arbitrary offsets
     * should embed sugar-cellbuffer for cell-grid semantics (cross-lib
     * seam, not wired from here).
     */
    public function view(): string
    {
        return $this->renderFrame(trimRows: true);
    }

    /**
     * Render the canvas with every row padded to its full width — trailing
     * cells (including a styled cell whose rune is a space) survive.
     * Overlay hosts that blit the frame onto an existing screen need the
     * full rectangle; {@see view()} trims it away.
     */
    public function viewPadded(): string
    {
        return $this->renderFrame(trimRows: false);
    }

    private function renderFrame(bool $trimRows): string
    {
        $rows = [];
        for ($y = 0; $y < $this->height; $y++) {
            $row = '';
            $active = ''; // SGR prefix currently open on the terminal ('' = default)
            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->cells[$y][$x] ?? null;
                if ($cell !== null && $cell->width === 0) {
                    // Right half of a wide cell: the anchor already emitted it.
                    continue;
                }
                $rune = $cell === null || $cell->rune === '' ? ' ' : $cell->rune;
                $open = $cell === null || $cell->style === null
                    ? ''
                    : self::styleOpen($cell->style, $rune);
                if ($open === null) {
                    // Exotic style (padding/border chars around the rune):
                    // flush the open run and emit the cell verbatim, exactly
                    // as the per-cell renderer did.
                    if ($active !== '') {
                        $row .= Ansi::reset();
                        $active = '';
                    }
                    $wrapped = $cell->style->render($rune);
                    $row .= $wrapped;
                    if (preg_match('/\x1b\[[0-9;]*m\z/', $wrapped) !== 1) {
                        $row .= Ansi::reset();
                    } else {
                        $active = '';
                    }
                    continue;
                }
                if ($open !== $active) {
                    if ($active !== '') {
                        $row .= Ansi::reset();
                    }
                    if ($open !== '') {
                        $row .= $open;
                    }
                    $active = $open;
                }
                $row .= $rune;
                if ($cell !== null && $cell->width >= 2) {
                    $x++; // the wide rune spans this column and the next
                }
            }
            if ($active !== '') {
                $row .= Ansi::reset();
            }
            $rows[] = $trimRows ? rtrim($row) : $row;
        }
        return implode("\n", $rows);
    }

    /**
     * The SGR prefix {@see Style::render()} puts around `$rune`, or ''
     * when the style renders the rune bare. Returns null when the
     * rendered shape is not a plain `<sgr><rune><reset>` sandwich (the
     * caller then emits the full render inline). Memoized per process:
     * hot frames repeat the same style/rune pair thousands of times.
     *
     * The memo is keyed by the Style OBJECT via WeakMap, never by
     * spl_object_id() in a plain array. PHP recycles object ids the
     * moment a Style is freed, so an (id, rune) key can hand a LATER,
     * entirely different Style the earlier one's SGR prefix. Under a
     * pcov-instrumented full-suite run that collision reallocated heat
     * cells with stale default-palette bytes over withColors() values
     * (HeatmapTest crosstalk, LANE-HM 2026-11-11). A WeakMap cannot
     * false-hit — it is indexed by the live object itself — and it
     * prunes entries when their Style dies, so the memo is also bounded
     * rather than growing monotonically for the process lifetime.
     *
     * @var \WeakMap<Style, array<string, string|null>>|null
     */
    private static ?\WeakMap $styleOpenMemo = null;

    private static function styleOpen(Style $style, string $rune): ?string
    {
        $memo = self::$styleOpenMemo ??= new \WeakMap();
        $perRune = $memo[$style] ?? [];
        if (array_key_exists($rune, $perRune)) {
            return $perRune[$rune];
        }
        $wrapped = $style->render($rune);
        $runePos = strrpos($wrapped, $rune);
        if ($runePos === false) {
            $result = null;
        } else {
            $tail = substr($wrapped, $runePos + strlen($rune));
            $result = ($tail !== '' && $tail !== Ansi::reset())
                ? null
                : substr($wrapped, 0, $runePos);
        }
        // WeakMap::offsetGet copies the value array, so the whole
        // (rune => prefix) map must be re-assigned to persist.
        $perRune[$rune] = $result;
        $memo[$style] = $perRune;
        return $result;
    }
}
