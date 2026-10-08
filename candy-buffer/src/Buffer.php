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
                'Buffer dimensions must be positive'
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
     * resets it first), and OSC sequences — including the OSC 8 hyperlink
     * opens/closes toAnsi() emits — are skipped without entering the cell
     * grid, so even linked output round-trips as text. Restoration is
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
                'Buffer dimensions must be positive'
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
                    // CSI SGR sequence: find its end (m) and merge its params
                    // onto the live style. Multi-segment styling such as
                    // "\x1b[1m\x1b[31m" accumulates — the previous
                    // last-segment-wins cache silently dropped bold.
                    $end = strpos($ansi, 'm', $idx);
                    if ($end === false) {
                        $idx++;
                        continue;
                    }
                    $params = substr($ansi, $idx + 2, $end - $idx - 2);
                    $currentStyle = self::styleFromSgr($params, $currentStyle);
                    $idx = $end + 1;
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
            // contract (see its docblock; repo-wide callers are the
            // candy-buffer and sugar-veil test suites only, zero production
            // call sites). The canonical display-width primitive is
            // SugarCraft\Core\Util\Width (candy-core — UAX isWide table,
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
     * saturates to 255. This matches sugar-veil's penFromSgr(), which reads
     * the same SGR stream. A truncated extended colour (`38;5`, `38;2;1`)
     * ends the parse rather than letting its operands be misread as
     * attribute codes.
     *
     * @param string $params Semicolon-separated SGR parameter numbers
     * @param Style|null $carry Style in effect before this sequence
     */
    private static function styleFromSgr(string $params, ?Style $carry = null): ?Style
    {
        $codes = array_map('intval', explode(';', $params));

        $fg = $carry?->fg();
        $bg = $carry?->bg();
        $attrs = $carry?->attrs() ?? 0;

        $count = count($codes);
        for ($i = 0; $i < $count; $i++) {
            $p = $codes[$i];
            if ($p === 0) {
                $fg = null;
                $bg = null;
                $attrs = 0;
            } elseif ($p >= 30 && $p <= 37) {
                $fg = self::ansiColorToHex($p - 30);
            } elseif ($p >= 40 && $p <= 47) {
                $bg = self::ansiColorToHex($p - 40);
            } elseif ($p >= 90 && $p <= 97) {
                $fg = self::ansiColorToHex($p - 90, true);
            } elseif ($p >= 100 && $p <= 107) {
                $bg = self::ansiColorToHex($p - 100, true);
            } elseif ($p === 39) {
                $fg = null;
            } elseif ($p === 49) {
                $bg = null;
            } elseif ($p === 38 || $p === 48) {
                if ($i + 4 < $count && $codes[$i + 1] === 2) {
                    $rgb = (self::channel($codes[$i + 2]) << 16)
                        | (self::channel($codes[$i + 3]) << 8)
                        | self::channel($codes[$i + 4]);
                    $i += 4; // consumed 2;r;g;b
                } elseif ($i + 2 < $count && $codes[$i + 1] === 5) {
                    $rgb = self::xterm256ToHex($codes[$i + 2]);
                    $i += 2; // consumed 5;n
                } else {
                    // Malformed / truncated extended colour: its trailing
                    // parameters are colour operands, not SGR codes — a bare
                    // `38;5` must not set blink, nor `38;2` faint. Stop and
                    // keep the pen built so far.
                    break;
                }
                if ($p === 38) {
                    $fg = $rgb;
                } else {
                    $bg = $rgb;
                }
            } elseif ($p === 1) {
                $attrs |= Style::ATTR_BOLD;
            } elseif ($p === 2) {
                $attrs |= Style::ATTR_FAINT;
            } elseif ($p === 3) {
                $attrs |= Style::ATTR_ITALIC;
            } elseif ($p === 4) {
                $attrs |= Style::ATTR_UNDERLINE;
            } elseif ($p === 5) {
                $attrs |= Style::ATTR_BLINK;
            } elseif ($p === 7) {
                $attrs |= Style::ATTR_REVERSE;
            } elseif ($p === 8) {
                $attrs |= Style::ATTR_INVISIBLE;
            } elseif ($p === 9) {
                $attrs |= Style::ATTR_STRIKE;
            }
            // Additional SGR params can be extended here
        }

        return $fg !== null || $bg !== null || $attrs !== 0
            ? new Style($fg, $bg, $attrs)
            : null;
    }

    /**
     * Clamp one 38;2 / 48;2 colour component into 0-255, the way terminals
     * saturate an out-of-range value — `300` paints as 255, never as the
     * `300 & 0xFF = 44` a bit-mask would produce.
     */
    private static function channel(int $component): int
    {
        return max(0, min(255, $component));
    }

    /**
     * Standard xterm 256-colour palette index → 0xRRGGBB: 0-7 and 8-15 map
     * through ansiColorToHex() (so `38;5;1` and `31` agree), 16-231 the 6×6×6
     * cube, 232-255 the grayscale ramp. The index is clamped into 0-255 first,
     * the same saturation channel() applies to truecolor components, so an
     * out-of-range index can never produce a value outside 0xRRGGBB.
     */
    private static function xterm256ToHex(int $n): int
    {
        $n = self::channel($n);
        if ($n < 8) {
            return self::ansiColorToHex($n);
        }
        if ($n < 16) {
            return self::ansiColorToHex($n - 8, true);
        }
        if ($n >= 232) {
            $gray = 8 + ($n - 232) * 10;
            return ($gray << 16) | ($gray << 8) | $gray;
        }
        $cube = $n - 16;
        $step = static fn(int $v): int => $v === 0 ? 0 : 55 + $v * 40;
        return ($step(intdiv($cube, 36)) << 16)
            | ($step(intdiv($cube % 36, 6)) << 8)
            | $step($cube % 6);
    }

    private static function ansiColorToHex(int $idx, bool $bright = false): int
    {
        $palette = [
            // Standard colors
            0x000000, // 0 black
            0xff0000, // 1 red
            0x00ff00, // 2 green
            0xffff00, // 3 yellow
            0x0000ff, // 4 blue
            0xff00ff, // 5 magenta
            0x00ffff, // 6 cyan
            0xffffff, // 7 white
        ];
        $base = $palette[$idx] ?? 0xffffff;
        if ($bright) {
            if ($idx === 0) {
                // Bright black has nothing to lighten from #000000 — the
                // +40% model below would keep it pure black, painting
                // invisible black-on-black text on dark terminals. The
                // canonical bright-palette value (xterm colour 8, and
                // candy-palette's StandardColors::$brightBlack) is mid
                // grey 0x7F7F7F, so SGR 90 / `38;5;8` resolve there.
                return 0x7f7f7f;
            }
            // Bright: lighten the color
            $r = ($base >> 16) & 0xff;
            $g = ($base >> 8) & 0xff;
            $b = $base & 0xff;
            $r = min(255, (int) ($r + ($r * 0.4)));
            $g = min(255, (int) ($g + ($g * 0.4)));
            $b = min(255, (int) ($b + ($b * 0.4)));
            return ($r << 16) | ($g << 8) | $b;
        }
        return $base;
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
     * $region->origin and $region->size, clipped to buffer edges.
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

        for ($dy = 0; $dy < $region->height; $dy++) {
            for ($dx = 0; $dx < $region->width; $dx++) {
                $srcCol = $dx;
                $srcRow = $dy;

                if ($srcCol >= $srcW || $srcRow >= $srcH) {
                    continue;
                }

                $dstCol = $region->origin->col + $dx;
                $dstRow = $region->origin->row + $dy;

                if ($dstCol < 0 || $dstRow < 0) {
                    // Negative origins: silently skip cells outside buffer bounds.
                    continue;
                }

                if ($dstCol >= $this->width || $dstRow >= $this->height) {
                    continue;
                }

                $this->placeWithPair($grid, $dstCol, $dstRow, $source->grid[$srcRow * $srcW + $srcCol]);
            }
        }

        return $this->mutate(['grid' => $grid]);
    }

    /**
     * Efficiently fill a rectangular $region with a single $cell.
     *
     * Wide-cell pairing is enforced per written cell exactly as in
     * {@see withCellAt()}.
     *
     * @param Region $region The region to fill (clipped to buffer bounds)
     * @param Cell   $cell   The cell to write at each position in the region
     */
    public function fill(Region $region, Cell $cell): self
    {
        $grid = $this->grid;

        for ($dy = 0; $dy < $region->height; $dy++) {
            for ($dx = 0; $dx < $region->width; $dx++) {
                $dstCol = $region->origin->col + $dx;
                $dstRow = $region->origin->row + $dy;

                if ($dstCol < 0 || $dstRow < 0) {
                    continue;
                }

                if ($dstCol >= $this->width || $dstRow >= $this->height) {
                    continue;
                }

                $this->placeWithPair($grid, $dstCol, $dstRow, $cell);
            }
        }

        return $this->mutate(['grid' => $grid]);
    }

    /**
     * Extract a sub-region of this buffer into a new buffer.
     *
     * @param Region $region The region to copy (origin is always (0,0) in the returned buffer)
     * @return self A new buffer containing the copied region
     */
    public function copy(Region $region): self
    {
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
     * @return list<\SugarCraft\Buffer\Diff\DiffOp> Ordered delta operations
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
     * @param list<\SugarCraft\Buffer\Diff\DiffOp> $ops
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
                    // Note: applyDiff is for round-trip testing only.
                    // Hyperlinks can't be perfectly reconstructed from ops
                    // since we only store the url string in SetHyperlinkOp.
                    // Reconstruct with only the URL to document this lossy behaviour.
                    $link = $cell->link() !== null
                        ? new Hyperlink($cell->link()->url())
                        : null;
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
                    $out .= "\x1b]8;;\x1b\\";
                    $prevLink = null;
                }

                // Emit SGR only when the style value changes.
                if (!Style::valuesEqual($cell->style(), $prevStyle)) {
                    $out .= $this->emitSgr($cell->style());
                    $prevStyle = $cell->style();
                }

                // Open hyperlink when a link appears that is not already open.
                if ($cell->link() !== null && !Hyperlink::valuesEqual($cell->link(), $prevLink)) {
                    $url = $cell->link()->url();
                    $id = $cell->link()->id();
                    $idPart = $id !== '' ? (";" . $id) : "";
                    $out .= "\x1b]8" . $idPart . ";" . $url . "\x1b\\";
                    $prevLink = $cell->link();
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
     *    to its left instead of leaving a half-open pair.
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
                $grid[$idx + 1] = Cell::continuation();
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
     * @param array{width: int, height: int, grid: list<array>} $data
     */
    public function __unserialize(array $data): void
    {
        $this->width = $data['width'];
        $this->height = $data['height'];
        $this->grid = array_map(function (array $cellData): Cell {
            $style = $cellData['style'] !== null
                ? new Style($cellData['style']['fg'], $cellData['style']['bg'], $cellData['style']['attrs'])
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
