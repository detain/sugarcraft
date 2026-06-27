<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * A 2-D grid of terminal cells with immutable mutation semantics.
 *
 * Buffer is the core data model for rendering: it represents a rectangular
 * slice of a terminal with per-cell rune, style, hyperlink, and width
 * information. All mutation happens through fluent with*() methods that
 * return new Buffer instances — the original is never modified.
 *
 * Mirrors charmbracelet/vte's Buffer and charmbracelet/lipgloss's
 * Style as the foundation for terminal rendering.
 */
final class Buffer
{
    /**
     * @param list<Cell> $grid Flat grid: index = $row * $width + $col
     */
    private function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly array $grid,
    ) {}

    /**
     * Default factory — creates a width×height grid of blank cells.
     */
    public static function new(int $width, int $height): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(
                'Buffer dimensions must be positive'
            );
        }
        $size = $width * $height;
        $blank = Cell::new();
        $grid = array_fill(0, $size, $blank);

        return new self($width, $height, $grid);
    }

    /**
     * Build a Buffer directly from a pre-computed flat cell grid (O(1) wrap).
     *
     * Unlike {@see new()} followed by repeated {@see withCellAt()} calls
     * (each O(n) → O(n²) overall), this wraps an already-built grid in a
     * single step, enabling O(n) bulk construction.
     *
     * @param list<Cell> $grid Flat grid of exactly width*height cells, index = $row*$width + $col.
     *
     * @throws \InvalidArgumentException when dimensions are non-positive or
     *                                   $grid does not contain exactly
     *                                   width*height cells.
     */
    public static function fromGrid(int $width, int $height, array $grid): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(
                'Buffer dimensions must be positive'
            );
        }
        $expected = $width * $height;
        if (count($grid) !== $expected) {
            throw new \InvalidArgumentException(
                "Buffer grid must contain exactly {$expected} cells ({$width}x{$height}), got " . count($grid)
            );
        }

        return new self($width, $height, $grid);
    }

    // ─── Accessors ─────────────────────────────────────────────────────

    /** Width in cells. */
    public function width(): int { return $this->width; }

    /** Height in cells. */
    public function height(): int { return $this->height; }

    /** Bounding region of the entire buffer. */
    public function region(): Region
    {
        return new Region(Position::new(0, 0), $this->width, $this->height);
    }

    /**
     * Bounds-checked cell accessor.
     *
     * @throws \OutOfRangeException when $col or $row is outside the grid
     */
    public function cellAt(int $col, int $row): Cell
    {
        $this->assertInBounds($col, $row);

        return $this->grid[$row * $this->width + $col];
    }

    // ─── Immutable mutations ─────────────────────────────────────────────

    /**
     * Return a new Buffer with $cell placed at ($col, $row).
     *
     * @throws \OutOfRangeException when coordinates are outside the grid
     */
    public function withCellAt(int $col, int $row, Cell $cell): self
    {
        $this->assertInBounds($col, $row);

        $grid = $this->grid;
        $grid[$row * $this->width + $col] = $cell;

        return $this->mutate(['grid' => $grid]);
    }

    /**
     * Blit (composite) $source into this buffer's $region.
     *
     * Cells from $source are copied into the region defined by
     * $region->origin and $region->size, clipped to buffer edges.
     */
    public function withRegion(Region $region, Buffer $source): self
    {
        $grid = $this->grid;
        $srcW = $source->width;
        $srcH = $source->height;

        for ($dy = 0; $dy < $region->height; $dy++) {
            for ($dx = 0; $dx < $region->width; $dx++) {
                $srcCol = $dx;
                $srcRow = $dy;

                if ($srcCol >= $srcW || $srcRow >= $srcH) {
                    continue;
                }

                $dstCol = $region->origin->col + $dx;
                $dstRow = $region->origin->row + $dy;

                if ($dstCol >= $this->width || $dstRow >= $this->height) {
                    continue;
                }

                $grid[$dstRow * $this->width + $dstCol] = $source->cellAt($srcCol, $srcRow);
            }
        }

        return $this->mutate(['grid' => $grid]);
    }

    // ─── Diff ───────────────────────────────────────────────────────────

    /**
     * Compare this buffer against $previous and return the list of
     * operations needed to transform $previous into this buffer.
     *
     * Simple row-by-row cell walk: for each changed cell, emit a
     * MoveCursorOp to its position (if needed), then a SetCellOp.
     * Adjacent changed cells with the same style are grouped into one
     * SetCellOp.  Horizontal runs of 2+ identical cells use RepeatRunOp.
     * Large runs of blank cells use EraseRunOp.
     *
     * @param Buffer $previous The previous frame buffer (same dimensions)
     * @return list<SugarCraft\Buffer\Diff\DiffOp> Ordered delta operations
     * @throws \InvalidArgumentException if buffer dimensions differ
     */
    public function diff(Buffer $previous): array
    {
        if ($previous->width !== $this->width || $previous->height !== $this->height) {
            throw new \InvalidArgumentException(
                "Buffer dimensions must match for diff: previous ({$previous->width}x{$previous->height}) vs current ({$this->width}x{$this->height})"
            );
        }

        $ops = [];
        $lastEmittedCol = -1;
        $lastEmittedRow = -1;
        $pendingStyle = null;
        $pendingLinkUrl = null;

        for ($row = 0; $row < $this->height; $row++) {
            for ($col = 0; $col < $this->width; $col++) {
                $prevCell = $previous->grid[$row * $this->width + $col];
                $currCell = $this->grid[$row * $this->width + $col];

                // Skip continuation cells (wide-char padding).
                if ($currCell->width === 0) {
                    continue;
                }

                if ($this->cellsEqual($prevCell, $currCell)) {
                    continue;
                }

                // Cell differs. Emit MoveCursorOp if not at this position.
                if ($col !== $lastEmittedCol || $row !== $lastEmittedRow) {
                    $ops[] = new Diff\MoveCursorOp($col, $row);
                    $lastEmittedCol = $col;
                    $lastEmittedRow = $row;
                }

                // Emit style transition if needed.
                if ($currCell->style() !== $pendingStyle) {
                    $ops[] = new Diff\SetStyleOp($currCell->style());
                    $pendingStyle = $currCell->style();
                }

                // Emit hyperlink open/close if needed.
                $currLinkUrl = $currCell->link()?->url();
                if ($currLinkUrl !== $pendingLinkUrl) {
                    if ($pendingLinkUrl !== null) {
                        $ops[] = new Diff\SetHyperlinkOp(null);
                    }
                    if ($currLinkUrl !== null) {
                        $ops[] = new Diff\SetHyperlinkOp($currCell->link());
                    }
                    $pendingLinkUrl = $currLinkUrl;
                }

                // Collect a run of consecutive changed cells with same style
                // for repeat detection.
                $run = [$currCell];
                $runStyle = $currCell->style();
                $runLinkUrl = $currLinkUrl;
                $runLen = 1;
                $nextCol = $col + 1;

                while ($nextCol < $this->width) {
                    $nextCell = $this->grid[$row * $this->width + $nextCol];
                    if ($nextCell->width === 0) {
                        $nextCol++;
                        continue;
                    }
                    $nextPrev = $previous->grid[$row * $this->width + $nextCol];
                    // Collect if cell differs AND has same pending style/link.
                    if (!$this->cellsEqual($nextPrev, $nextCell)
                        && $nextCell->style() === $runStyle
                        && $nextCell->link()?->url() === $runLinkUrl
                    ) {
                        // This cell also differs AND has same style.
                        $run[] = $nextCell;
                        $runLen++;
                        $nextCol++;
                    } else {
                        break;
                    }
                }

                // Check for blank default run eligible for EraseRunOp.
                // EraseRunOp (ECH) erases cells without SGR transitions, ideal
                // for large cleared regions of default-style cells.
                if ($runLen >= 3 && $this->isBlankDefaultRun($run)) {
                    $ops[] = new Diff\EraseRunOp($runLen);
                } elseif ($runLen === 1) {
                    $ops[] = new Diff\SetCellOp($run);
                } else {
                    // Check for repeat (2+ identical cells with same style).
                    $first = $run[0];
                    $repeatRune = $first->rune();
                    $repeatWidth = $first->width() > 0 ? $first->width() : 1;
                    $allSame = true;
                    for ($i = 1; $i < $runLen; $i++) {
                        if ($run[$i]->rune() !== $repeatRune
                            || $run[$i]->style() !== $runStyle
                            || $run[$i]->link()?->url() !== $runLinkUrl
                        ) {
                            $allSame = false;
                            break;
                        }
                    }

                    if ($allSame && $runLen >= 2 && $repeatWidth === 1) {
                        // First cell + REP for remainder.
                        $ops[] = new Diff\SetCellOp([$first]);
                        $ops[] = new Diff\RepeatRunOp($repeatRune, $runLen - 1, 1);
                    } else {
                        // Emit as-is.
                        $ops[] = new Diff\SetCellOp($run);
                    }
                }

                // Advance cursor past the run.
                $lastEmittedCol = $col + $runLen - 1;
                $col += $runLen - 1; // -1 because for-loop increments
            }
        }

        // Optimise.
        $optimiser = new Diff\DiffOptimiser();
        return $optimiser->optimise($ops);
    }

    /**
     * Check whether two cells are equal in their rendered representation.
     */
    private function cellsEqual(Cell $a, Cell $b): bool
    {
        return $a->rune() === $b->rune()
            && $a->style() === $b->style()
            && $a->link()?->url() === $b->link()?->url()
            && $a->width() === $b->width();
    }

    /**
     * Check whether all cells in a run are blank default cells
     * (rune=' ', null style, null link, width=1).
     *
     * Such cells are eligible for EraseRunOp (ECH) encoding.
     *
     * @param list<Cell> $run
     */
    private function isBlankDefaultRun(array $run): bool
    {
        foreach ($run as $cell) {
            if ($cell->rune() !== ' '
                || $cell->style() !== null
                || $cell->link() !== null
                || $cell->width() !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a list of DiffOps to $source buffer and return the resulting
     * buffer (round-trip inverse of diff).
     *
     * This is useful for testing:  current.diff(prev).apply(prev) === current.
     *
     * @param list<DiffOp> $ops
     * @return self
     */
    public function applyDiff(array $ops): self
    {
        // Start from a copy of this buffer as the working grid.
        $grid = $this->grid;
        $cursorCol = 0;
        $cursorRow = 0;
        $pendingStyle = null;
        $pendingLinkUrl = null;

        foreach ($ops as $op) {
            if ($op instanceof Diff\MoveCursorOp) {
                $cursorCol = $op->col;
                $cursorRow = $op->row;
            } elseif ($op instanceof Diff\SetStyleOp) {
                $pendingStyle = $op->style;
            } elseif ($op instanceof Diff\SetHyperlinkOp) {
                $pendingLinkUrl = $op->hyperlink?->url();
            } elseif ($op instanceof Diff\SetCellOp) {
                foreach ($op->cells as $cell) {
                    if ($cursorCol >= $this->width || $cursorRow >= $this->height) {
                        continue;
                    }
                    $width = $cell->width() > 0 ? $cell->width() : 1;
                    // Build cell with pending style/link merged.
                    $style = $cell->style() ?? $pendingStyle;
                    $link = $cell->link();
                    // Note: applyDiff is for round-trip testing only.
                    // Hyperlinks can't be perfectly reconstructed from ops
                    // since we only store the url string in SetHyperlinkOp.
                    $grid[$cursorRow * $this->width + $cursorCol] = new Cell(
                        $cell->rune(),
                        $style,
                        $link,
                        $width,
                    );
                    // If wide char, fill the next cell as continuation.
                    if ($width === 2) {
                        $nextCol = $cursorCol + 1;
                        if ($nextCol < $this->width) {
                            $grid[$cursorRow * $this->width + $nextCol] = Cell::continuation();
                        }
                    }
                    $cursorCol += $width;
                }
            } elseif ($op instanceof Diff\EraseRunOp) {
                // ECH: replace $count cells at cursor with blank (style=null).
                for ($i = 0; $i < $op->count; $i++) {
                    $c = $cursorCol + $i;
                    if ($c >= $this->width) {
                        break;
                    }
                    $grid[$cursorRow * $this->width + $c] = Cell::new();
                }
                $cursorCol += $op->count;
            } elseif ($op instanceof Diff\RepeatRunOp) {
                // REP: repeat the rune $count times at current cursor.
                if ($op->count > 0) {
                    $rune = $op->rune;
                    // Width=0 treated as 1.
                    $width = $op->width > 0 ? $op->width : 1;
                    for ($i = 0; $i < $op->count; $i++) {
                        $c = $cursorCol + $i;
                        if ($c >= $this->width) {
                            break;
                        }
                        $grid[$cursorRow * $this->width + $c] = new Cell($rune, $pendingStyle, null, $width);
                        if ($width === 2) {
                            $nextCol = $c + 1;
                            if ($nextCol < $this->width) {
                                $grid[$cursorRow * $this->width + $nextCol] = Cell::continuation();
                            }
                        }
                    }
                    $cursorCol += $op->count;
                }
            }
        }

        return $this->mutate(['grid' => $grid]);
    }

    // ─── ANSI rendering ─────────────────────────────────────────────────

    /**
     * Render the buffer to an ANSI escape string.
     *
     * Walks the grid row by row, emitting SGR sequences only when
     * a cell's style differs from the previous cell, and OSC 8 hyperlink
     * sequences around linked cells.
     *
     * @return string Raw ANSI byte string suitable for terminal output
     */
    public function toAnsi(): string
    {
        $out = '';
        $prevStyle = null;
        $prevLink = null;

        for ($row = 0; $row < $this->height; $row++) {
            if ($row > 0) {
                $out .= "\n";
            }
            for ($col = 0; $col < $this->width; $col++) {
                $cell = $this->grid[$row * $this->width + $col];

                // Continuation cells (wide-char padding) are skipped.
                if ($cell->width === 0) {
                    continue;
                }

                // Close hyperlink when link changes or before new style.
                if ($prevLink !== null && $cell->link() !== $prevLink) {
                    $out .= "\x1b]8;;\x1b\\";
                }

                // Emit SGR only when style changes.
                if ($cell->style() !== $prevStyle) {
                    $out .= $this->emitSgr($cell->style());
                    $prevStyle = $cell->style();
                }

                // Open hyperlink when link appears.
                if ($cell->link() !== null && $cell->link() !== $prevLink) {
                    $url = $cell->link()->url();
                    $id = $cell->link()->id();
                    $idPart = $id !== '' ? (";" . $id) : "";
                    $out .= "\x1b]8" . $idPart . ";" . $url . "\x1b\\";
                    $prevLink = $cell->link();
                } elseif ($cell->link() === null) {
                    $prevLink = null;
                }

                $out .= $cell->rune();
            }
        }

        // Close any open hyperlink and reset SGR.
        if ($prevLink !== null) {
            $out .= "\x1b]8;;\x1b\\";
        }
        if ($prevStyle !== null) {
            $out .= "\x1b[0m";
        }

        return $out;
    }

    /**
     * Emit the SGR sequence for a cell style (or reset if null).
     */
    private function emitSgr(?Style $style): string
    {
        if ($style === null) {
            return "\x1b[0m";
        }

        $codes = ['0'];

        if ($style->fg() !== null) {
            $rgb = $style->fg();
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $codes[] = "38;2;{$r};{$g};{$b}";
        }

        if ($style->bg() !== null) {
            $rgb = $style->bg();
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $codes[] = "48;2;{$r};{$g};{$b}";
        }

        $attrs = $style->attrs();
        if ($attrs !== 0) {
            if ($attrs & Style::ATTR_BOLD)       { $codes[] = '1'; }
            if ($attrs & Style::ATTR_FAINT)     { $codes[] = '2'; }
            if ($attrs & Style::ATTR_ITALIC)    { $codes[] = '3'; }
            if ($attrs & Style::ATTR_UNDERLINE) { $codes[] = '4'; }
            if ($attrs & Style::ATTR_BLINK)     { $codes[] = '5'; }
            if ($attrs & Style::ATTR_REVERSE)  { $codes[] = '7'; }
            if ($attrs & Style::ATTR_STRIKE)   { $codes[] = '9'; }
            if ($attrs & Style::ATTR_OVERLINE) { $codes[] = '53'; }
        }

        return "\x1b[" . implode(';', $codes) . "m";
    }

    // ─── Internals ─────────────────────────────────────────────────────

    /**
     * @return static
     */
    private function mutate(array $changes): static
    {
        return new static(...array_merge(
            ['width' => $this->width, 'height' => $this->height, 'grid' => $this->grid],
            $changes,
        ));
    }

    /**
     * @throws \OutOfRangeException
     */
    private function assertInBounds(int $col, int $row): void
    {
        if ($col < 0 || $col >= $this->width || $row < 0 || $row >= $this->height) {
            throw new \OutOfRangeException(
                "Cell ({$col}, {$row}) is out of buffer bounds ({$this->width}x{$this->height})"
            );
        }
    }
}
