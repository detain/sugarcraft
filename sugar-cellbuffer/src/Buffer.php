<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

/**
 * A 2-D grid of terminal cells with immutable mutation semantics.
 *
 * Buffer is the core data model for rendering: it represents a rectangular
 * slice of a terminal with per-cell rune, style, hyperlink, and width
 * information. All mutation happens through fluent with*() methods that
 * return new Buffer instances — the original is never modified.
 *
 * The cell-grid model follows charmbracelet/lipgloss's Cell/Style design
 * and the terminal buffer representation in charmbracelet/x/vt.
 */
final class Buffer implements \JsonSerializable
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
                "Buffer dimensions must be positive (got {$width}x{$height})"
            );
        }
        $size = $width * $height;
        $blank = Cell::new();
        $grid = array_fill(0, $size, $blank);

        return new self($width, $height, $grid);
    }

    /**
     * Build a Buffer from an ANSI-encoded string produced by {@see toAnsi()}.
     *
     * Test-support factory — not for accurate terminal state replay. SGR
     * sequences accumulate onto the live style (code 0 within a sequence
     * resets it first); every other CSI frame — cursor addressing, DEC
     * modes, this package's own CUP/ECH/REP output, and an 'm'-terminated
     * frame whose parameters carry private/intermediate bytes — is consumed
     * without entering the cell grid (a direct {@see Style::fromSgr()} call
     * still refuses malformed parameters loudly); and OSC sequences —
     * including the OSC 8 hyperlink opens/closes toAnsi() emits — are
     * skipped too, so even linked output round-trips as text. Restoration is
     * lossy by contract: hyperlinks are never re-attached to cells, SGRs
     * outside the parsed vocabulary are ignored, and multibyte runes are
     * split byte-wise (one byte per cell).
     *
     * A printable count that differs from width×height is padded with
     * blanks (too few) or truncated (too many) — lenient by design for
     * hand-written fixtures.
     *
     * @param string|null $ansi  Raw ANSI output from toAnsi(), or null for a
     *                           blank width×height buffer.
     * @param int         $width  Frame width in cells
     * @param int         $height Frame height in rows
     *
     * @throws \InvalidArgumentException when either dimension is non-positive.
     */
    public static function fromString(?string $ansi, int $width, int $height): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(
                "Buffer dimensions must be positive (got {$width}x{$height})"
            );
        }
        if ($ansi === null || $ansi === '') {
            return self::new($width, $height);
        }

        // Parse escape-aware; collect [rune, style, width] tuples.
        $runs = [];
        $currentStyle = null; // live SGR state, accumulated across sequences

        $ansiLen = strlen($ansi);
        $idx = 0;

        while ($idx < $ansiLen) {
            $ch = $ansi[$idx];

            if ($ch === "\x1b" && isset($ansi[$idx + 1])) {
                $next = $ansi[$idx + 1];

                if ($next === ']') {
                    // OSC sequence (e.g. toAnsi()'s OSC 8 hyperlink frames):
                    // consume through its ST (ESC \) or BEL terminator so the
                    // bytes never leak into the cell grid as garbage runes.
                    $tail = $idx + 2 + strcspn($ansi, "\x07\x1b", $idx + 2);
                    if ($tail < $ansiLen && $ansi[$tail] === "\x1b") {
                        $idx = $tail + 2; // swallow ESC + the ST backslash
                    } else {
                        $idx = $tail + 1; // BEL consumed; unterminated OSC swallows the rest
                    }
                    continue;
                }

                if ($next === '[') {
                    // CSI frame: consume exactly what a terminal consumes —
                    // parameter/intermediate bytes (0x20-0x3F) up to a final
                    // byte (0x40-0x7E) — instead of hunting for a bare 'm'
                    // anywhere later (which mis-swallowed private modes like
                    // \x1b[?25l and turned non-SGR frames into throw bait or
                    // printable garbage). Only an SGR-shaped frame (final 'm',
                    // digits with ;/: separators, or empty == reset) touches
                    // the live style; Multi-segment styling such as
                    // "\x1b[1m\x1b[31m" accumulates. Every other CSI — cursor
                    // addressing, DEC modes, this package's own CUP/ECH/REP —
                    // is dropped without entering the grid, keeping the
                    // documented leniency of a test-support parser.
                    $scan = $idx + 2;
                    while ($scan < $ansiLen) {
                        $byte = ord($ansi[$scan]);
                        if ($byte < 0x20 || $byte > 0x3F) {
                            break;
                        }
                        $scan++;
                    }
                    if ($scan >= $ansiLen) {
                        // Unterminated CSI: the frame swallows the rest of
                        // the stream, exactly like a terminal holding a
                        // pending sequence.
                        break;
                    }
                    if ($ansi[$scan] === 'm') {
                        $params = substr($ansi, $idx + 2, $scan - $idx - 2);
                        if ($params === '' || preg_match('/^[\d;:]+$/', $params) === 1) {
                            $currentStyle = self::styleFromSgr($params === '' ? '0' : $params, $currentStyle);
                        }
                        // Else: 'm' terminus polluted with private/intermediate
                        // bytes — discard the frame (a direct Style::fromSgr()
                        // call still refuses it loudly; that contract lives
                        // with its own callers, not this fixture parser).
                    }
                    $idx = $scan + 1; // consume through the final byte
                    continue;
                }
            }

            if ($ch === "\n") {
                // Row delimiter — skip but do not add to cell grid
                $idx++;
                continue;
            }

            // Printable character
            $rune = $ch;
            // Width is deliberately pinned to 1: this loop iterates BYTES, so
            // a multibyte rune already lands one byte per cell — consulting a
            // width table here without first tokenizing UTF-8 would relabel
            // the parse, not fix it. fromString() is a test-support factory by
            // contract (see its docblock). The canonical display-width primitive is
            // SugarCraft\Core\Util\Width (sugar-core — UAX isWide table,
            // ANSI-aware); it is not consulted here because byte-wise width
            // pinning is this fixture factory's documented contract, not an
            // oversight. Wide characters are already correct where cells are
            // actually constructed: Cell carries width 1/2 with width-0
            // continuation cells, and diff() honours them — continuation
            // skip in its inner loop, REP merge guarded by width===1. A full
            // UTF-8 tokenizer for this byte-wise factory remains a separate
            // future candidate.
            $runeWidth = 1;
            $runs[] = [$rune, $currentStyle, $runeWidth];
            $idx++;
        }

        $expected = $width * $height;
        $actual = count($runs);

        // Lenient: accept any string length by truncating or padding with
        // blanks (the contract documented on fromString()). Plain-ASCII
        // toAnsi() output always yields exactly width*height printable
        // bytes; multibyte runes land one byte per cell under this
        // byte-wise parse, so they may overrun — truncation is deliberate.
        if ($actual !== $expected) {
            if ($actual < $expected) {
                // Pad with blank cells
                $blank = Cell::new();
                for ($i = $actual; $i < $expected; $i++) {
                    $runs[] = [' ', null, 1];
                }
            } else {
                // Truncate
                $runs = array_slice($runs, 0, $expected);
            }
        }

        $grid = [];
        foreach ($runs as [$rune, $style, $runeWidth]) {
            $grid[] = new Cell($rune, $style, null, $runeWidth);
        }

        return new self($width, $height, $grid);
    }

    /**
     * Merge one SGR parameter string into the live style.
     *
     * Handles: 0 (full reset, discarding $carry), 30-37 (fg), 40-47 (bg),
     * 1 (bold), 4 (underline), 90-97 (bright fg), 100-107 (bright bg),
     * 38;2;r;g;b / 48;2;r;g;b truecolor (the form toAnsi() emits),
     * 38;5;n / 48;5;n xterm 256-colour indices, the 39/49 default-colour
     * codes, and attributes 2,3,5,7,8,9 (faint, italic, blink, reverse,
     * invisible, strike).
     *
     * Out-of-range colour operands are CLAMPED, not bit-masked: a truecolor
     * component of 300 saturates to 255 (a `& 0xFF` mask would wrap it to
     * 44 and paint an unrelated colour), and a 256-colour index above 255
     * saturates to 255. This matches sugar-overlay's penFromSgr(), which reads
     * the same SGR stream. A truncated extended colour (`38;5`, `38;2;1`)
     * ends the parse rather than letting its operands be misread as
     * attribute codes.
     *
     * @param string $params Semicolon-separated SGR parameter numbers
     * @param Style|null $carry Style in effect before this sequence
     */
    private static function styleFromSgr(string $params, ?Style $carry = null): ?Style
    {
        return Style::fromSgr($params, $carry);
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
                "Buffer dimensions must be positive (got {$width}x{$height})"
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
     * Extract plain text, row by row, joined with "\n".
     *
     * Width-0 continuation cells contribute nothing (their lead already
     * carries the rune); cells with no rune fall back to a single space so
     * blank columns stay visible to search/clipboard layers. When a region
     * is given, extraction is clipped to the region ∩ buffer intersection
     * (negative origins simply read from the region's start cell).
     */
    public function text(?Region $region = null): string
    {
        $region ??= $this->region();
        $rows = [];
        for ($dy = 0; $dy < $region->height; $dy++) {
            $row = $region->origin->row + $dy;
            if ($row < 0 || $row >= $this->height) {
                continue;
            }
            $line = '';
            for ($dx = 0; $dx < $region->width; $dx++) {
                $col = $region->origin->col + $dx;
                if ($col < 0 || $col >= $this->width) {
                    continue;
                }
                $cell = $this->grid[$row * $this->width + $col];
                if ($cell->width() === 0) {
                    continue; // Continuation: the lead already emitted this glyph.
                }
                $line .= $cell->rune() === '' ? ' ' : $cell->rune();
            }
            $rows[] = $line;
        }

        return implode("\n", $rows);
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
     * Wide-cell pairing is enforced here: placing a width-2 cell writes the
     * width-0 continuation into the next column (clipped at the right edge,
     * mirroring applyDiff's discipline); replacing a wide lead, or writing
     * onto a continuation, sweeps the partner so no orphaned half-pair can
     * survive the mutation. {@see fromGrid()} stays the unvalidated bulk
     * escape hatch for callers that build grids themselves.
     *
     * @throws \OutOfRangeException when coordinates are outside the grid
     */
    public function withCellAt(int $col, int $row, Cell $cell): self
    {
        $this->assertInBounds($col, $row);

        $grid = $this->grid;
        $this->placeWithPair($grid, $col, $row, $cell);

        return $this->mutate(['grid' => $grid]);
    }

    /**
     * Blit (composite) $source into this buffer's $region.
     *
     * Cells from $source are copied into the region defined by
     * $region->origin and $region->width/$region->height, clipped to the
     * buffer edges.
     *
     * Note: negative origins are silently clipped — a region origin at
     * (-1, -1) starts one cell left/above the buffer's top-left corner
     * and no cells are written until the region enters buffer bounds.
     *
     * Wide-cell pairing is enforced per destination write exactly as in
     * {@see withCellAt()} — a source pair blits intact, and a lead whose
     * continuation falls outside the blit still gets its partner written.
     */
    public function withRegion(Region $region, Buffer $source): self
    {
        $grid = $this->grid;
        $srcW = $source->width;
        $srcH = $source->height;

        // Loop bounds are clipped to the region ∩ source ∩ buffer
        // intersection BEFORE iterating: a data-driven region far larger
        // than the grid costs the intersection, never the full area.
        $dxStart = max(0, -$region->origin->col);
        $dyStart = max(0, -$region->origin->row);
        $dxEnd = min($region->width, $srcW, $this->width - $region->origin->col);
        $dyEnd = min($region->height, $srcH, $this->height - $region->origin->row);

        for ($dy = $dyStart; $dy < $dyEnd; $dy++) {
            for ($dx = $dxStart; $dx < $dxEnd; $dx++) {
                $this->placeWithPair(
                    $grid,
                    $region->origin->col + $dx,
                    $region->origin->row + $dy,
                    $source->grid[$dy * $srcW + $dx],
                );
            }
        }

        return $this->mutate(['grid' => $grid]);
    }

    /**
     * Efficiently fill a rectangular $region with a single $cell.
     *
     * Wide-cell pairing is enforced per written cell exactly as in
     * {@see withCellAt()} — a width-2 lead's continuation partner may land
     * one column OUTSIDE the fill region at its right edge (same shape as
     * withRegion()), and a lead whose partner falls outside the region
     * still gets it written in bounds; a region-terminating sweep leaves a
     * single lead cell at the end of a filled wide run, as elsewhere.
     *
     * @param Region $region The region to fill (clipped to buffer bounds)
     * @param Cell   $cell   The cell to write across the region
     */
    public function fill(Region $region, Cell $cell): self
    {
        $grid = $this->grid;

        // Loop bounds clipped to the region ∩ buffer intersection: a
        // data-driven region far larger than the grid costs the
        // intersection, never the full area.
        $dxStart = max(0, -$region->origin->col);
        $dyStart = max(0, -$region->origin->row);
        $dxEnd = min($region->width, $this->width - $region->origin->col);
        $dyEnd = min($region->height, $this->height - $region->origin->row);

        for ($dy = $dyStart; $dy < $dyEnd; $dy++) {
            for ($dx = $dxStart; $dx < $dxEnd; $dx++) {
                $this->placeWithPair($grid, $region->origin->col + $dx, $region->origin->row + $dy, $cell);
            }
        }

        return $this->mutate(['grid' => $grid]);
    }

    /**
     * Upper bound on the cells a single {@see copy()} may extract. The copy
     * output is region-sized by contract, so unlike fill()/withRegion() its
     * cost cannot be clipped to an intersection — it must be capped.
     */
    public const MAX_COPY_CELLS = 1_000_000;

    /**
     * Extract a sub-region of this buffer into a new buffer.
     *
     * The output is always exactly $region-sized; positions outside this
     * buffer's bounds are filled with blank cells (this differs from
     * withRegion()/fill(), which CLIP away out-of-bounds work — copy()
     * pads, because its contract is a definite output shape).
     *
     * Wide-cell pairs are blitted verbatim: cropping a pair's partner
     * column leaves the lead without its continuation (documented hazard
     * of the region window, not normalised here).
     *
     * @param Region $region The region to copy (origin is always (0,0) in the returned buffer)
     * @return self A new buffer containing the copied region
     * @throws \InvalidArgumentException on a zero-area region (an output
     *         buffer cannot be empty) or a region whose area exceeds
     *         {@see self::MAX_COPY_CELLS}.
     */
    public function copy(Region $region): self
    {
        if ($region->width <= 0 || $region->height <= 0) {
            throw new \InvalidArgumentException(
                "Buffer::copy() requires a region with positive dimensions (got {$region->width}x{$region->height})",
            );
        }

        if ($region->width * $region->height > self::MAX_COPY_CELLS) {
            throw new \InvalidArgumentException(sprintf(
                'Buffer::copy() region of %d cells exceeds the %d-cell safety cap.',
                $region->width * $region->height,
                self::MAX_COPY_CELLS,
            ));
        }

        $newWidth = $region->width;
        $newHeight = $region->height;
        $newGrid = [];

        for ($dy = 0; $dy < $region->height; $dy++) {
            for ($dx = 0; $dx < $region->width; $dx++) {
                $srcCol = $region->origin->col + $dx;
                $srcRow = $region->origin->row + $dy;

                if ($srcCol < 0 || $srcRow < 0) {
                    // Outside source bounds — use blank cell.
                    $newGrid[] = Cell::new();
                    continue;
                }

                if ($srcCol >= $this->width || $srcRow >= $this->height) {
                    // Outside source bounds — use blank cell.
                    $newGrid[] = Cell::new();
                    continue;
                }

                $newGrid[] = $this->grid[$srcRow * $this->width + $srcCol];
            }
        }

        return self::fromGrid($newWidth, $newHeight, $newGrid);
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
     * @return list<\SugarCraft\CellBuffer\Diff\DiffOp> Ordered delta operations
     * @throws \InvalidArgumentException if buffer dimensions differ
     */
    public function diff(Buffer $previous): array
    {
        if ($previous->width !== $this->width || $previous->height !== $this->height) {
            throw new \InvalidArgumentException(
                "Buffer dimensions must match for diff: previous ({$previous->width}x{$previous->height}) vs current ({$this->width}x{$this->height})"
            );
        }

        if ($previous === $this) {
            return [];
        }

        $ops = [];
        $lastEmittedCol = -1;
        $lastEmittedRow = -1;
        $pendingStyle = null;
        $pendingLink = null; // tracked by VALUE (url+id), not object identity

        for ($row = 0; $row < $this->height; $row++) {
            for ($col = 0; $col < $this->width; $col++) {
                $prevCell = $previous->grid[$row * $this->width + $col];
                $currCell = $this->grid[$row * $this->width + $col];

                // Skip continuation cells (wide-char padding).
                if ($currCell->width === 0) {
                    continue;
                }

                if ($prevCell->equals($currCell)) {
                    continue;
                }

                // Cell differs. Emit MoveCursorOp if not at this position.
                if ($col !== $lastEmittedCol || $row !== $lastEmittedRow) {
                    $ops[] = new Diff\MoveCursorOp($col, $row);
                    $lastEmittedCol = $col;
                    $lastEmittedRow = $row;
                }

                // Emit style transition if needed (value equality, so output
                // is independent of whether equal styles share an instance).
                if (!Style::valuesEqual($currCell->style(), $pendingStyle)) {
                    $ops[] = new Diff\SetStyleOp($currCell->style());
                    $pendingStyle = $currCell->style();
                }

                // Emit hyperlink open/close if needed. Comparison covers url
                // AND id: an id-only change must re-emit OSC 8, which the old
                // url-string tracking silently dropped.
                $currLink = $currCell->link();
                if (!Hyperlink::valuesEqual($currLink, $pendingLink)) {
                    if ($pendingLink !== null) {
                        $ops[] = new Diff\SetHyperlinkOp(null);
                    }
                    if ($currLink !== null) {
                        $ops[] = new Diff\SetHyperlinkOp($currLink);
                    }
                    $pendingLink = $currLink;
                }

                // Collect a run of consecutive changed cells with same style
                // for repeat detection.
                $run = [$currCell];
                $runStyle = $currCell->style();
                $runLink = $currLink;
                $runLen = 1;
                // Columns the run physically occupies (leads count their own
                // width; continuations are consumed by their lead), so the
                // cursor advance matches what the encoder will step over.
                $runCols = $currCell->width() > 0 ? $currCell->width() : 1;
                $nextCol = $col + 1;

                while ($nextCol < $this->width) {
                    $nextCell = $this->grid[$row * $this->width + $nextCol];
                    if ($nextCell->width === 0) {
                        $nextCol++;
                        continue;
                    }
                    $nextPrev = $previous->grid[$row * $this->width + $nextCol];
                    // Collect if cell differs AND has same pending style/link.
                    // Style and link compared by value, not identity.
                    if (!$nextPrev->equals($nextCell)
                        && Style::valuesEqual($nextCell->style(), $runStyle)
                        && Hyperlink::valuesEqual($nextCell->link(), $runLink)
                    ) {
                        // This cell also differs AND has same style.
                        $run[] = $nextCell;
                        $runLen++;
                        $runCols += $nextCell->width() > 0 ? $nextCell->width() : 1;
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
                        // Style and link equality by value, not identity
                        if ($run[$i]->rune() !== $repeatRune
                            || !Style::valuesEqual($run[$i]->style(), $runStyle)
                            || !Hyperlink::valuesEqual($run[$i]->link(), $runLink)
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

                // Advance cursor past the run (column span, not cell count —
                // wide leads consume their continuation columns).
                $lastEmittedCol = $col + $runCols - 1;
                $col += $runCols - 1; // -1 because for-loop increments
            }
        }

        // Optimise.
        $optimiser = new Diff\DiffOptimiser();
        return $optimiser->optimise($ops);
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
     * Apply a list of DiffOps to this buffer and return the resulting
     * buffer (round-trip inverse of diff):
     * $previous->applyDiff($current->diff($previous)) renders as $current.
     *
     * @param list<\SugarCraft\CellBuffer\Diff\DiffOp> $ops
     * @return self
     * @throws \InvalidArgumentException on a MoveCursorOp with negative coords
     */
    public function applyDiff(array $ops): self
    {
        // Start from a copy of this buffer as the working grid.
        $grid = $this->grid;
        $cursorCol = 0;
        $cursorRow = 0;
        $pendingStyle = null;

        foreach ($ops as $op) {
            if ($op instanceof Diff\MoveCursorOp) {
                if ($op->col < 0 || $op->row < 0) {
                    throw new \InvalidArgumentException(
                        "MoveCursorOp coordinates must not be negative: ({$op->col}, {$op->row})"
                    );
                }
                $cursorCol = $op->col;
                $cursorRow = $op->row;
            } elseif ($op instanceof Diff\SetStyleOp) {
                $pendingStyle = $op->style;
            } elseif ($op instanceof Diff\SetHyperlinkOp) {
                // Terminal-state framing: cells carry their own links, so the
                // open/close ops need no separate accumulation here.
            } elseif ($op instanceof Diff\SetCellOp) {
                foreach ($op->cells as $cell) {
                    if ($cursorCol >= $this->width || $cursorRow >= $this->height) {
                        continue;
                    }
                    $width = $cell->width() > 0 ? $cell->width() : 1;
                    // Build cell with pending style merged. The cell's own
                    // hyperlink is carried through intact (url AND id) — the
                    // old reconstruction dropped the id and the comment
                    // blamed SetHyperlinkOp, which carries the full link.
                    $style = $cell->style() ?? $pendingStyle;
                    $link = $cell->link();
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
                // ECH erases in-place; the logical cursor does NOT advance.
                for ($i = 0; $i < $op->count; $i++) {
                    $c = $cursorCol + $i;
                    if ($c >= $this->width) {
                        break;
                    }
                    $grid[$cursorRow * $this->width + $c] = Cell::new();
                }
            } elseif ($op instanceof Diff\RepeatRunOp) {
                // REP: repeat the rune $count times at current cursor.
                if ($op->count > 0) {
                    $rune = $op->rune;
                    // Width=0 treated as 1.
                    $width = $op->width > 0 ? $op->width : 1;
                    for ($i = 0; $i < $op->count; $i++) {
                        $c = $cursorCol + $i * $width;
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
                    $cursorCol += $op->count * $width;
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

                // Close hyperlink when the link value changes (url+id compared
                // by VALUE — instance-independent output, paired with the
                // open decision below so equal-but-unshared links never
                // produce nested OSC 8 opens without a close).
                if ($prevLink !== null && !Hyperlink::valuesEqual($cell->link(), $prevLink)) {
                    $out .= Hyperlink::CLOSE;
                    $prevLink = null;
                }

                // Emit SGR only when the style value changes.
                if (!Style::valuesEqual($cell->style(), $prevStyle)) {
                    $out .= $this->emitSgr($cell->style());
                    $prevStyle = $cell->style();
                }

                // Open hyperlink when a link appears that is not already open.
                // The frame's spelling — including the spec's empty-params
                // double semicolon on the id-less form — lives once in
                // Hyperlink::toAnsi(), shared with the delta encoder, so both
                // wires are byte-identical by construction.
                if ($cell->link() !== null && !Hyperlink::valuesEqual($cell->link(), $prevLink)) {
                    $out .= $cell->link()->toAnsi();
                    $prevLink = $cell->link();
                }

                $out .= $cell->rune();
            }
        }

        // Close any open hyperlink and reset SGR.
        if ($prevLink !== null) {
            $out .= Hyperlink::CLOSE;
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
        return Diff\SgrEmitter::emit($style);
    }

    // ─── Internals ─────────────────────────────────────────────────────

    /**
     * Write one cell into a working grid, keeping the wide-cell pair
     * invariant of the Cell docblock structurally true afterwards:
     *
     *  - a width-2 lead gets its width-0 continuation in the right neighbour
     *    (a straddle at the last column keeps only the lead — the same
     *    silent in-range clamp applyDiff() performs);
     *  - replacing a lead with a real cell blanks a left-behind continuation
     *    so the cleared column repaints instead of ghosting;
     *  - writing a real cell onto a continuation blanks the stranded lead
     *    to its left instead of leaving a half-open pair;
     *  - a width-2 lead landing on a partner slot that is itself the lead of
     *    another pair blanks that pair's continuation one column further
     *    right, so overwriting a wide cell never orphans half a pair.
     *
     * Continuation-to-continuation writes pass through untouched, so a
     * well-formed source pair blits byte-identically.
     *
     * @param non-empty-array<int, Cell> $grid working grid, mutated in place
     */
    private function placeWithPair(array &$grid, int $col, int $row, Cell $cell): void
    {
        $idx = $row * $this->width + $col;
        $previous = $grid[$idx];
        $grid[$idx] = $cell;

        if ($cell->width() === 2) {
            if ($col + 1 < $this->width) {
                $displaced = $grid[$idx + 1];
                $grid[$idx + 1] = Cell::continuation();
                if ($displaced->width() === 2 && $col + 2 < $this->width) {
                    $grid[$idx + 2] = Cell::new();
                }
            }
        } elseif ($cell->width() !== 0
            && $previous->width() === 2
            && $col + 1 < $this->width
            && $grid[$idx + 1]->width() === 0
        ) {
            $grid[$idx + 1] = Cell::new();
        }

        if ($cell->width() !== 0 && $previous->width() === 0 && $col > 0) {
            $left = $grid[$idx - 1];
            if ($left->width() === 2) {
                $grid[$idx - 1] = Cell::new();
            }
        }
    }

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

    /**
     * Serialization hook for caching/IPC use cases.
     *
     * @return array{width: int, height: int, grid: list<array>}
     */
    public function __serialize(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'grid' => array_map(fn(Cell $cell) => $cell->__serialize(), $this->grid),
        ];
    }

    /**
     * Unserialization hook for caching/IPC use cases.
     *
     * Fail-closed: the dimension and grid-size invariants are re-checked
     * here (and every inner Cell/Hyperlink rebuild routes its own
     * constructor guards), so a forged payload cannot mint a structurally
     * invalid buffer.
     *
     * @param array{width: int, height: int, grid: list<array>} $data
     */
    public function __unserialize(array $data): void
    {
        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        $grid = $data['grid'] ?? [];
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException("Buffer dimensions must be positive (got {$width}x{$height})");
        }
        if (!is_array($grid) || count($grid) !== $width * $height) {
            throw new \InvalidArgumentException('Buffer grid must contain exactly ' . ($width * $height) . " cells ({$width}x{$height}), got " . (is_array($grid) ? count($grid) : '???'));
        }

        $this->width = $width;
        $this->height = $height;
        $this->grid = array_map(function (array $cellData): Cell {
            $style = $cellData['style'] !== null
                ? new Style(
                    $cellData['style']['fg'],
                    $cellData['style']['bg'],
                    $cellData['style']['attrs'],
                    $cellData['style']['fgSlot'] ?? null,
                    $cellData['style']['bgSlot'] ?? null,
                    $cellData['style']['ul'] ?? null,
                    $cellData['style']['ulSlot'] ?? null,
                )
                : null;
            $link = $cellData['link'] !== null
                ? new Hyperlink($cellData['link']['url'], $cellData['link']['id'])
                : null;
            return new Cell($cellData['rune'], $style, $link, $cellData['width']);
        }, $data['grid']);
    }

    /**
     * JSON serialization support.
     *
     * @return array{width: int, height: int, grid: list<array>}
     */
    public function jsonSerialize(): array
    {
        return $this->__serialize();
    }
}
