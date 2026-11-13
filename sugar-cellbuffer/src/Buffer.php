<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

use SugarCraft\CellBuffer\Support\WidePairPlacement;

/**
 * A 2-D grid of terminal cells with immutable mutation semantics.
 *
 * Buffer is the core data model for rendering: it represents a rectangular
 * slice of a terminal with per-cell rune, style, hyperlink, and width
 * information. All mutation happens through fluent with*() methods that
 * return new Buffer instances — the original is never modified.
 *
 * Mirrors charmbracelet/x/cellbuf.Buffer — the rectangular cell grid as
 * the shared terminal render surface (module `charmbracelet/x/cellbuf`,
 * the `cellbuf/` package of github.com/charmbracelet/x; the separate
 * `x/vt`-style GitHub repos were consolidated into the `x` monorepo).
 * The immutable copy-on-write shape is this port's own law — upstream
 * buffers are mutable.
 */
final class Buffer implements \JsonSerializable
{
    use WidePairPlacement;

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
     * Validation is strict about TYPE and LAYOUT — every entry must be a
     * Cell (a stray string/array used to sail through and detonate at the
     * first reader), and the array must be a list so index = row*width+col
     * holds by construction — and deliberately silent about wide-pair
     * ALIGNMENT: {@see copy()} crops can legitimately begin on a
     * continuation or end on a stranded lead (slice geometry, not a bug),
     * so alignment is the caller's contract; the lenient read-back of
     * misaligned grids is pinned behaviour (WideCellPairingTest and the
     * BufferTest orphan pins), not a validated state.
     *
     * @param list<Cell> $grid Flat grid of exactly width*height cells, index = $row*$width + $col.
     *
     * @throws \InvalidArgumentException when dimensions are non-positive,
     *                                   $grid does not contain exactly
     *                                   width*height cells, the grid is not
     *                                   a list, or any entry is not a Cell.
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
        if (!array_is_list($grid)) {
            throw new \InvalidArgumentException(
                'Buffer grid must be a list (row-major keys 0..width*height-1); a keyed array desyncs every index computation'
            );
        }
        foreach ($grid as $idx => $cell) {
            if (!$cell instanceof Cell) {
                throw new \InvalidArgumentException(
                    'Buffer grid entry ' . $idx . ' must be a Cell, got ' . get_debug_type($cell)
                );
            }
        }

        return new self($width, $height, $grid);
    }

    /**
     * Fresh mutable scratch for bulk painting (CB-34): setCellAt()/fillRect()
     * write in place under the identical pair law as the fluent with*()
     * paths, and toBuffer() freezes the result through {@see fromGrid()}.
     * One allocation per cell, not one Buffer clone per write.
     */
    public static function builder(int $width, int $height): BufferBuilder
    {
        return new BufferBuilder($width, $height);
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
     * (negative origins simply read from the region's start cell). Clipped
     * rows are returned SHORTER than the region width — no padding is
     * inserted — so offset→coordinate arithmetic must not assume a fixed
     * row length across the clipped tail.
     */
    public function text(?Region $region = null): string
    {
        $region ??= $this->region();
        $rows = [];
        for ($dy = 0; $dy < $region->height(); $dy++) {
            $row = $region->origin()->row() + $dy;
            if ($row < 0 || $row >= $this->height) {
                continue;
            }
            $line = '';
            for ($dx = 0; $dx < $region->width(); $dx++) {
                $col = $region->origin()->col() + $dx;
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
     * width-0 continuation into the next column (a lead straddling the last
     * column DEGRADES to a blank — the same placement-time degrade
     * applyDiff() performs); replacing a wide lead, or writing
     * onto a continuation, sweeps the partner so no orphaned half-pair can
     * survive the mutation. {@see fromGrid()} stays the bulk wrap for
     * callers that build grids themselves — it validates type and layout
     * but not wide-pair alignment (see its docblock).
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
     * $region->origin(), $region->width() and $region->height(), clipped to
     * the buffer edges.
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

        // The swept destination is the region ∩ source ∩ buffer
        // intersection (Region::intersect once per pair, before iterating):
        // a data-driven region far larger than the grid costs the
        // intersection, never the full area. Source cells sit at their
        // offsets from the region origin inside that destination.
        $clip = $region
            ->intersect(Region::new($region->origin(), $srcW, $srcH))
            ?->intersect($this->region());

        if ($clip !== null) {
            $colEnd = $clip->right();
            $rowEnd = $clip->bottom();
            $originCol = $region->origin()->col();
            $originRow = $region->origin()->row();
            for ($row = $clip->origin()->row(); $row <= $rowEnd; $row++) {
                for ($col = $clip->origin()->col(); $col <= $colEnd; $col++) {
                    $this->placeWithPair(
                        $grid,
                        $col,
                        $row,
                        $source->grid[($row - $originRow) * $srcW + ($col - $originCol)],
                    );
                }
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

        // Sweep the region ∩ buffer intersection (Region::intersect before
        // iterating): a data-driven region far larger than the grid costs
        // the intersection, never the full area.
        $clip = $region->intersect($this->region());

        if ($clip !== null) {
            $colEnd = $clip->right();
            $rowEnd = $clip->bottom();
            for ($row = $clip->origin()->row(); $row <= $rowEnd; $row++) {
                for ($col = $clip->origin()->col(); $col <= $colEnd; $col++) {
                    $this->placeWithPair($grid, $col, $row, $cell);
                }
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
        $regionWidth = $region->width();
        $regionHeight = $region->height();
        $originCol = $region->origin()->col();
        $originRow = $region->origin()->row();

        if ($regionWidth <= 0 || $regionHeight <= 0) {
            throw new \InvalidArgumentException(
                "Buffer::copy() requires a region with positive dimensions (got {$regionWidth}x{$regionHeight})",
            );
        }

        if ($regionWidth * $regionHeight > self::MAX_COPY_CELLS) {
            throw new \InvalidArgumentException(sprintf(
                'Buffer::copy() region of %d cells exceeds the %d-cell safety cap.',
                $regionWidth * $regionHeight,
                self::MAX_COPY_CELLS,
            ));
        }

        $newWidth = $regionWidth;
        $newHeight = $regionHeight;
        $newGrid = [];

        for ($dy = 0; $dy < $regionHeight; $dy++) {
            for ($dx = 0; $dx < $regionWidth; $dx++) {
                $srcCol = $originCol + $dx;
                $srcRow = $originRow + $dy;

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
                        // Style and link equality by value, not identity.
                        // Width too: a same-rune run of MIXED widths ([w1
                        // lead, w2 lead] from a run-length grid) must never
                        // REP at the first lead's width — the replay would
                        // repaint every position width-1 and desync the
                        // pair columns against the documented round-trip
                        // law. Mixed widths fall through to SetCellOp,
                        // which honours each cell's own width.
                        if ($run[$i]->rune() !== $repeatRune
                            || $run[$i]->width() !== $first->width()
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
     *
     * Boundary policy (both sides of zero, asymmetric by design and now
     * stated): NEGATIVE cursor coordinates are a programmer error in the op
     * stream and THROW; writes that land outside the grid on the positive
     * side (row below the last row, column past the last column) are skipped
     * fail-soft — an out-of-frame op is treated like the off-screen part of
     * a legitimate repaint, never as a fatal.
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
                $opCol = $op->col();
                $opRow = $op->row();
                if ($opCol < 0 || $opRow < 0) {
                    throw new \InvalidArgumentException(
                        "MoveCursorOp coordinates must not be negative: ({$opCol}, {$opRow})"
                    );
                }
                $cursorCol = $opCol;
                $cursorRow = $opRow;
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
                    if ($width === 2 && $cursorCol + 1 >= $this->width) {
                        // Straddle at the last column: degrade to a blank,
                        // mirroring placeWithPair() — a lone 2-column lead
                        // overruns the row past the frame. The cursor still
                        // advances by the declared width, as the wire does.
                        $grid[$cursorRow * $this->width + $cursorCol] = Cell::new();
                        $cursorCol += $width;
                        continue;
                    }
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
                    // Wide lead: fill the columns it covers. Width 2 writes
                    // its one continuation; larger run-length spans (the
                    // border-fill idiom, span law in Cell) mask every column
                    // they occupy, so a replay re-establishes the same
                    // painted shape the source grid carried.
                    for ($pairCol = $cursorCol + 1; $pairCol < $cursorCol + $width && $pairCol < $this->width; $pairCol++) {
                        $grid[$cursorRow * $this->width + $pairCol] = Cell::continuation();
                    }
                    $cursorCol += $width;
                }
            } elseif ($op instanceof Diff\EraseRunOp) {
                // ECH: replace $count cells at cursor with blank (style=null).
                // ECH erases in-place; the logical cursor does NOT advance.
                // A cursor below the last row has nowhere to erase: skip the
                // whole op, the same skip-don't-throw law the SetCellOp
                // branch applies on both axes (an unchecked write here
                // silently EXTENDED the grid past width*height).
                if ($cursorRow >= $this->height) {
                    continue;
                }
                for ($i = 0; $i < $op->count; $i++) {
                    $c = $cursorCol + $i;
                    if ($c >= $this->width) {
                        break;
                    }
                    $grid[$cursorRow * $this->width + $c] = Cell::new();
                }
            } elseif ($op instanceof Diff\RepeatRunOp) {
                // REP: repeat the rune $count times at current cursor.
                if ($op->count > 0 && $cursorRow < $this->height) {
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

    /**
     * Diff against $previous and encode the delta in one step — the
     * canonical two-liner (`$curr->diff($prev)` fed to a fresh
     * `DiffEncoder`) collapsed for the frame-loop call sites that
     * hand-wired it (sugar-flexbox, sugar-panels, sugar-overlay,
     * sugar-dashboard, examples/diff_frames.php all repeat the pattern).
     */
    public function diffToAnsi(self $previous): string
    {
        return (new Diff\DiffEncoder())->encode($this->diff($previous));
    }

    // ─── ANSI rendering ─────────────────────────────────────────────────

    /**
     * Render the buffer to an ANSI escape string.
     *
     * Walks the grid row by row, emitting SGR sequences only when
     * a cell's style differs from the previous cell, and OSC 8 hyperlink
     * sequences around linked cells.
     *
     * @return string Raw ANSI byte string suitable for terminal output.
     *                Rows are joined with a bare LF — no CR, no cursor
     *                addressing — so the stream lands on the right rows
     *                only under cooked mode (ONLCR) or exact terminal
     *                width; raw-mode callers own the CRLF post-process
     *                (see the render contract in README).
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
        return Support\SgrEmitter::emit($style);
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
            // Shape guards for every key Cell::__serialize emits; a
            // nested style/link must be null or a full payload array.
            $rune = self::requireKey($cellData, 'rune', 'Buffer cell');
            $style = self::requireKey($cellData, 'style', 'Buffer cell');
            $link = self::requireKey($cellData, 'link', 'Buffer cell');
            $cellWidth = (int) self::requireKey($cellData, 'width', 'Buffer cell');
            if ($style !== null && !is_array($style)) {
                throw new \InvalidArgumentException('Buffer cell style payload must be an array or null');
            }
            if ($link !== null && !is_array($link)) {
                throw new \InvalidArgumentException('Buffer cell link payload must be an array or null');
            }
            // The span law and width bounds re-run inside new Cell() — the
            // constructor is the single source, not re-derived here.

            return new Cell(
                $rune,
                $style !== null ? new Style(
                    self::requireKey($style, 'fg', 'Style'),
                    self::requireKey($style, 'bg', 'Style'),
                    self::requireKey($style, 'attrs', 'Style'),
                    $style['fgSlot'] ?? null,
                    $style['bgSlot'] ?? null,
                    $style['ul'] ?? null,
                    $style['ulSlot'] ?? null,
                ) : null,
                $link !== null ? new Hyperlink(
                    self::requireKey($link, 'url', 'Hyperlink'),
                    self::requireKey($link, 'id', 'Hyperlink'),
                ) : null,
                $cellWidth,
            );
        }, $data['grid']);
    }

    /**
     * Shape guard shared by the family's __unserialize hooks: keys the
     * matching `__serialize` emits are required; a miss is a named
     * refusal, not a warning or a TypeError deep in the grid walk.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \InvalidArgumentException naming the payload and the key
     */
    private static function requireKey(array $data, string $key, string $payload): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw new \InvalidArgumentException("{$payload} payload missing key '{$key}'");
        }

        return $data[$key];
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
