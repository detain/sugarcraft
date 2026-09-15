<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

use Closure;
use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Core\Util\Width;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Theme;

/**
 * CSI handler for the vcr renderer path.
 *
 * Mutates CellGrid + Cursor directly in response to CSI dispatches.
 * Implements candy-ansi's {@see \SugarCraft\Ansi\Parser\CsiHandler} — the
 * shared parser dispatches completed sequences here via HandlerAdapter.
 *
 * Mirrors charmbracelet/x/vt CSI dispatcher (simplified for renderer use).
 */
final class CsiHandlerImpl implements CsiHandler
{
    private int $scrollTop = 0;
    private int $scrollBottom;

    private int $fg;
    private int $bg;
    private int $attrs = 0;

    /** Saved cursor for SCO SC/RC (CSI s / CSI u). */
    private ?Cursor $savedCursor = null;

    /** Last printed graphic grapheme, replayed by REP (CSI b). */
    private string $lastPrintable = '';

    /**
     * DECAWM (`CSI ? 7 h/l`) — auto-wrap on at power-on, mirroring the
     * emulator's {@see \SugarCraft\Vt\Mode\Mode::$autoWrap} default (PR #1417
     * fixed the emulator to the xterm/VT500 default of ON; the renderer
     * previously had no DECAWM concept and always wrapped).
     */
    private bool $autoWrap = true;

    /**
     * Phantom-cell / deferred-wrap flag, mirroring the emulator's
     * {@see \SugarCraft\Vt\Handler\ScreenHandler::$wrapPending} and xterm's
     * `wrapnext`: a graphic that lands in the last column parks the cursor
     * there WITHOUT advancing; the next graphic consumes the pending line
     * break first. The flag is set by GEOMETRY alone so a mid-stream DECAWM
     * toggle behaves like xterm — `?7l` printing overwrites the last cell
     * (the flag re-arms), `?7h` resumes the deferred wrap.
     */
    private bool $wrapPending = false;

    /**
     * Late-bound continuation flags ({@see \SugarCraft\Ansi\Parser\Parser::subparams()})
     * for the CSI sequence currently being dispatched. Wired by
     * {@see \SugarCraft\Vt\Terminal::new()} to the owning parser so SGR can
     * tell `CSI 4 : 3 m` (curly underline) from `CSI 4 ; 3 m` (underline +
     * italic). Unattached — direct construction in unit tests — SGR `4`
     * treats every parameter as an independent SGR, the renderer's
     * long-standing flat-list behaviour.
     *
     * @var (Closure(): list<bool>)|null
     */
    private ?Closure $subparamsProvider = null;

    public function __construct(
        private CellGrid $grid,
        private Cursor $cursor,
        private Theme $theme,
    ) {
        $this->fg = $theme->defaultFg;
        $this->bg = $theme->defaultBg;
        $this->scrollBottom = $grid->rows - 1;
    }

    public function grid(): CellGrid
    {
        return $this->grid;
    }

    public function cursor(): Cursor
    {
        return $this->cursor;
    }

    /** Deferred-wrap (phantom-cell) state — mirrors the emulator's `Terminal\Terminal::isWrapPending()`. */
    public function wrapPending(): bool
    {
        return $this->wrapPending;
    }

    /**
     * Wire the parser's sub-parameter continuation flags for SGR colon
     * handling. Called once at terminal construction; see
     * {@see $subparamsProvider}.
     *
     * @param Closure(): list<bool> $provider
     */
    public function attachSubparamsProvider(Closure $provider): void
    {
        $this->subparamsProvider = $provider;
    }

    public function printable(string $grapheme): void
    {
        if ($this->cursor->row < 0 || $this->cursor->row >= $this->grid->rows) {
            return;
        }

        $row = $this->cursor->row;
        $col = $this->cursor->col;

        $width = Width::string($grapheme);

        // Combining mark (width 0): attach to the host cell. Normally that is
        // the one before the cursor — but while a phantom cell is armed the
        // last graphic sits UNDER the cursor (parked at the margin), so the
        // mark belongs there (emulator: ScreenHandler::attachCombiningChar
        // keys the same way off wrapPending).
        if ($width <= 0) {
            $host = $this->wrapPending ? $col : $col - 1;
            if ($host >= 0) {
                $prev = $this->grid->get($row, $host);
                $updated = new Cell(
                    char: $prev->char . $grapheme,
                    fg: $this->fg,
                    bg: $this->bg,
                    attrs: $this->attrs,
                );
                $this->grid->set($row, $host, $updated);
            }
            return;
        }

        // Remember the last graphic grapheme so REP (CSI b) can replay it.
        $this->lastPrintable = $grapheme;

        // DECAWM wrap-through: a graphic landed in the last column and
        // deferred its advance; THIS graphic consumes it — move to
        // (row+1, 0), scrolling at the region bottom, before writing.
        // Mirrors {@see \SugarCraft\Vt\Handler\ScreenHandler::printChar()}.
        if ($this->wrapPending && $this->autoWrap) {
            $this->lineFeedWrap();
            $row = $this->cursor->row;
            $col = $this->cursor->col;
        }

        // If the character doesn't fit at all on this row: with DECAWM on,
        // take the deferred break and clamp if still too wide; with it off
        // the glyph is dropped and the cursor parks on the last column —
        // the phantom flag untouched (xterm cursor_off recomputes it only
        // when a cell is actually painted).
        if ($col + $width > $this->grid->cols) {
            if ($this->autoWrap) {
                $this->lineFeedWrap();
                $row = $this->cursor->row;
                $col = $this->cursor->col;
                if ($col + $width > $this->grid->cols) {
                    $this->cursor = $this->cursor->at($row, $this->grid->cols - 1);
                    $this->wrapPending = false;
                    return;
                }
            } else {
                $this->cursor = $this->cursor->at($row, $this->grid->cols - 1);
                return;
            }
        }

        // Write the character cell.
        $cell = new Cell(
            char: $grapheme,
            fg: $this->fg,
            bg: $this->bg,
            attrs: $this->attrs,
        );
        $this->grid->set($row, $col, $cell);

        // Write continuation cells for wide characters (e.g. CJK, emoji).
        for ($i = 1; $i < $width; $i++) {
            $this->grid->set($row, $col + $i, Cell::empty());
        }

        // Park on the last column with the phantom flag set instead of
        // advancing a full line; the advance happens on the next graphic
        // print (xterm `cursor_off` semantics).
        $nextCol = $col + $width;
        $this->cursor = $this->cursor->at($row, min($this->grid->cols - 1, $nextCol));
        $this->wrapPending = $nextCol >= $this->grid->cols;
    }

    public function cuu(int $count = 1): void
    {
        // Cursor motion disarms the deferred wrap (xterm reset_wrapnext);
        // clamp to the buffer, not the scroll region — matching the
        // emulator's CursorHandler with DECOM off (not modelled here).
        $this->wrapPending = false;
        $newRow = max(0, $this->cursor->row - $count);
        $this->cursor = $this->cursor->at($newRow, $this->cursor->col);
    }

    public function cud(int $count = 1): void
    {
        $this->wrapPending = false;
        $newRow = min($this->grid->rows - 1, $this->cursor->row + $count);
        $this->cursor = $this->cursor->at($newRow, $this->cursor->col);
    }

    public function cuf(int $count = 1): void
    {
        $this->wrapPending = false;
        $newCol = min($this->grid->cols - 1, $this->cursor->col + $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cub(int $count = 1): void
    {
        // Also the BS route (HandlerAdapter maps 0x08 here) — the emulator's
        // backspace moves out of the phantom cell the same way.
        $this->wrapPending = false;
        $newCol = max(0, $this->cursor->col - $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cup(int $row, int $col): void
    {
        $row = $row < 1 ? 1 : $row;
        $col = $col < 1 ? 1 : $col;

        // Absolute screen coordinates clamped to the buffer — mirroring the
        // emulator's CursorHandler::cup (DECOM off). The renderer's former
        // scroll-region clamping was a catalogued divergence: `CSI 1;1H`
        // under `CSI 2;4r` belongs on line 1, not yanked into the region.
        $absRow = max(0, min($this->grid->rows - 1, $row - 1));
        $absCol = max(0, min($this->grid->cols - 1, $col - 1));

        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($absRow, $absCol);
    }

    public function hvp(int $row, int $col): void
    {
        $this->cup($row, $col);
    }

    public function sgr(array $params): void
    {
        if (empty($params)) {
            $params = [0];
        }

        $subs = $this->currentSubparams();
        $i = 0;
        $paramCount = count($params);
        while ($i < $paramCount) {
            $p = (int) $params[$i];
            if ($p === -1) {
                $p = 0;
            }

            [$this->fg, $this->bg, $this->attrs, $i] = $this->applySgrParam(
                $p,
                array_values(array_map('intval', $params)),
                $i,
                $subs,
            );
        }
    }

    /**
     * Continuation flags for the CSI sequence being dispatched, or null
     * when no parser is attached (flat-parameter behaviour).
     *
     * @return list<bool>|null
     */
    private function currentSubparams(): ?array
    {
        $provider = $this->subparamsProvider;
        return $provider === null ? null : $provider();
    }

    /**
     * @param list<int> $params
     * @param list<bool>|null $subs Parser colon-continuation flags (null = no parser attached).
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function applySgrParam(int $p, array $params, int $i, ?array $subs): array
    {
        return match (true) {
            $p === 0 => [$this->theme->defaultFg, $this->theme->defaultBg, 0, $i + 1],
            $p === 1 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_BOLD, $i + 1],
            $p === 3 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_ITALIC, $i + 1],
            // `CSI 4 : 3 m` is ONE parameter (curly underline); `CSI 4 ; 3 m`
            // is TWO independent SGRs (underline + italic). The candy-ansi
            // parser flattens both to [4, 3]; only the continuation flags
            // distinguish them. Without a wired parser the renderer keeps its
            // historical flat reading. Styles beyond single have no bit in
            // this cell model — they all light ATTR_UNDERLINE, matching the
            // emulator's normalised `underline || style !== None`.
            $p === 4 => $this->sgrUnderline($params, $i, $subs),
            $p === 7 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_INVERSE, $i + 1],
            $p === 9 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_STRIKETHROUGH, $i + 1],
            $p === 22 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_BOLD & ~0x20000, $i + 1],
            $p === 23 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_ITALIC, $i + 1],
            $p === 24 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_UNDERLINE, $i + 1],
            $p === 27 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_INVERSE, $i + 1],
            // 25/28 reset blink/hidden — this cell model has no such bits and
            // the pen never lights them, so they are parity no-ops. 21
            // (double underline in xterm; a no-op in the emulator too —
            // charm folds it nowhere) falls through identically.
            $p === 29 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_STRIKETHROUGH, $i + 1],

            $p >= 30 && $p <= 37 => [$p - 30, $this->bg, $this->attrs, $i + 1],
            $p >= 40 && $p <= 47 => [$this->fg, $p - 40, $this->attrs, $i + 1],
            $p === 39 => [$this->theme->defaultFg, $this->bg, $this->attrs, $i + 1],
            $p === 49 => [$this->fg, $this->theme->defaultBg, $this->attrs, $i + 1],

            // The BRIGHT halves, palette slots 8-15. Missing until now, and
            // silently: an unhandled parameter falls through to `default`, which
            // keeps the PREVIOUS colour, so `\e[31mX\e[90mB` put both cells at
            // fg 1 and every assertion about a bright colour in this emulator
            // was measuring the colour before it. That reaches further than the
            // cell grid — candy-vcr's TapeToGif renders through this handler, so
            // a GIF painted the wrong colour too. Sibling
            // {@see \SugarCraft\Vt\Handler\SgrHandler} has always had these two
            // arms; the two SGR tables had simply diverged.
            $p >= 90 && $p <= 97 => [$p - 90 + 8, $this->bg, $this->attrs, $i + 1],
            $p >= 100 && $p <= 107 => [$this->fg, $p - 100 + 8, $this->attrs, $i + 1],

            $p === 38 => $this->sgrExtended($params, $i, fg: true),
            $p === 48 => $this->sgrExtended($params, $i, fg: false),
            // Underline colour — neither this pen nor the emulator's Sgr
            // model stores it; both CONSUME the extended triplet so its
            // components cannot masquerade as independent SGRs (the old
            // default-arm let `58;5;33` repaint the fg green on both paths).
            $p === 58 => $this->sgrExtendedDiscard($params, $i),
            $p === 59 => [$this->fg, $this->bg, $this->attrs, $i + 1],

            default => [$this->fg, $this->bg, $this->attrs, $i + 1],
        };
    }

    /**
     * SGR 4 with optional colon sub-parameter — see the table arm.
     *
     * @param list<int> $params
     * @param list<bool>|null $subs
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function sgrUnderline(array $params, int $i, ?array $subs): array
    {
        $colon = $subs !== null && ($subs[$i] ?? false);
        if ($colon) {
            // Mirror of SgrHandler::underlineStyle branch table: consume the
            // sub-parameter; every defined and unknown style lights the one
            // underline bit this cell model has; `4:0` clears it.
            $sub = $params[$i + 1] ?? -1;
            if ($sub === 0) {
                return [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_UNDERLINE, $i + 2];
            }
            if ($sub === -1) {
                // `CSI 4 : m` — empty sub-parameter; the emulator reads it as
                // plain single underline and leaves the default slot to the
                // next step (which resets it as SGR 0). Stay byte-identical.
                return [$this->fg, $this->bg, $this->attrs | Cell::ATTR_UNDERLINE, $i + 1];
            }
            return [$this->fg, $this->bg, $this->attrs | Cell::ATTR_UNDERLINE, $i + 2];
        }
        // Semicolon or flat form: plain underline; the next parameter is an
        // independent SGR (pre-fix behaviour preserved exactly when no
        // parser is attached).
        return [$this->fg, $this->bg, $this->attrs | Cell::ATTR_UNDERLINE, $i + 1];
    }

    /**
     * Handle 38;5;n (256-color) / 38;2;r;g;b (truecolor) and their 48;
     * background twins.
     *
     * The renderer cell stores palette indices only — no RGB slot — so a
     * truecolor triplet cannot be painted; but it MUST still be consumed.
     * Before this arm existed, `38;2;1;2;3` fell through as five independent
     * SGRs (2=…, 3=italic) and corrupted the pen. Dropping the colour to the
     * default pen is the closest faithful rendering; the emulator keeps the
     * RGB, a documented representation limit (VtParityTest::DIVERGENCE_NOTE).
     *
     * @param list<int> $params
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function sgrExtended(array $params, int $i, bool $fg): array
    {
        $kind = $params[$i + 1] ?? -1;
        if ($kind === 5) {
            $index = $params[$i + 2] ?? 0;
            if ($fg) {
                return [$index, $this->bg, $this->attrs, $i + 3];
            } else {
                return [$this->fg, $index, $this->attrs, $i + 3];
            }
        }
        if ($kind === 2) {
            // Truecolor triplet: consume r;g;b, leave the pen's palette slot
            // untouched (see docblock — no RGB storage in this model).
            return [$this->fg, $this->bg, $this->attrs, $i + 5];
        }
        return [$this->fg, $this->bg, $this->attrs, $i + 1];
    }

    /**
     * Handle 58 (underline colour): parse-and-discard the extended form so
     * its components never run as independent SGRs. 59 resets it — also a
     * no-op here, as the pen stores nothing to reset.
     *
     * @param list<int> $params
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function sgrExtendedDiscard(array $params, int $i): array
    {
        $kind = $params[$i + 1] ?? -1;
        if ($kind === 5) {
            return [$this->fg, $this->bg, $this->attrs, $i + 3];
        }
        if ($kind === 2) {
            return [$this->fg, $this->bg, $this->attrs, $i + 5];
        }
        return [$this->fg, $this->bg, $this->attrs, $i + 1];
    }

    public function gridRows(): int
    {
        return $this->grid->rows;
    }

    public function gridCols(): int
    {
        return $this->grid->cols;
    }

    public function ed(int $mode = 0): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;

        if ($mode === 0) {
            for ($r = $row; $r < $this->grid->rows; $r++) {
                for ($c = ($r === $row ? $col : 0); $c < $this->grid->cols; $c++) {
                    $this->grid->set($r, $c, Cell::empty());
                }
            }
        } elseif ($mode === 1) {
            for ($r = 0; $r <= $row; $r++) {
                $endCol = $r === $row ? $col + 1 : $this->grid->cols;
                for ($c = 0; $c < $endCol; $c++) {
                    $this->grid->set($r, $c, Cell::empty());
                }
            }
        } elseif ($mode === 2) {
            $this->grid = $this->grid->clear();
        }
    }

    public function el(int $mode = 0): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;

        if ($mode === 0) {
            for ($c = $col; $c < $this->grid->cols; $c++) {
                $this->grid->set($row, $c, Cell::empty());
            }
        } elseif ($mode === 1) {
            for ($c = 0; $c <= $col; $c++) {
                $this->grid->set($row, $c, Cell::empty());
            }
        } elseif ($mode === 2) {
            for ($c = 0; $c < $this->grid->cols; $c++) {
                $this->grid->set($row, $c, Cell::empty());
            }
        }
    }

    public function decset(int $mode, int $prefix = 0): void
    {
        // DEC private modes only — the emulator forwards `CSI h` without the
        // '?' prefix nowhere (see ScreenHandler case 'h'), and the renderer
        // must ignore the bare form identically.
        if ($prefix !== 0x3F /* '?' */) {
            return;
        }
        // DECTCEM: `CSI ? 25 h` SHOWS the cursor (set = enabled = visible).
        // The old arms had this inverted — a catalogued divergence (the grid
        // never noticed; only the cursor state did).
        match ($mode) {
            7 => $this->autoWrap = true,   // DECAWM on: deferred wrap resumes
            25 => $this->cursor = $this->cursor->shown(),
            default => null,               // Mouse/other modes: no grid effect.
        };
    }

    public function decrst(int $mode, int $prefix = 0): void
    {
        if ($prefix !== 0x3F /* '?' */) {
            return;
        }
        // DECAWM off: printing at the right margin overwrites the last cell
        // instead of wrapping — the phantom flag re-arms by geometry but is
        // never consumed while the mode stays off (xterm behaviour).
        match ($mode) {
            7 => $this->autoWrap = false,
            25 => $this->cursor = $this->cursor->hidden(),
            default => null,
        };
    }

    public function decstbm(int $top, int $bottom): void
    {
        if ($top < 1) {
            $top = 1;
        }
        if ($bottom > $this->grid->rows) {
            $bottom = $this->grid->rows;
        }
        if ($top > $bottom) {
            return;
        }

        $this->scrollTop = $top - 1;
        $this->scrollBottom = $bottom - 1;

        // DECSTBM does NOT yank the cursor into the new region — the
        // emulator's setScrollRegion only records the margins. The old
        // clamping was a catalogued divergence.
    }

    public function tbc(int $mode = 0): void
    {
        // No-op — tab clear is out of scope for the renderer path; $mode
        // is part of the CsiHandler contract, reserved for a future impl.
    }

    public function cbt(int $count = 1): void
    {
        // Tab-family motion deliberately leaves the phantom flag armed —
        // the emulator's HT/CHT/CBT route does (xterm _wrapnext survives).
        $newCol = max(0, $this->cursor->col - $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cht(int $count = 1): void
    {
        $newCol = min($this->grid->cols - 1, $this->cursor->col + $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    /**
     * CR — carriage return: move cursor to column 0 (row unchanged).
     * Column motion disarms the deferred wrap (xterm carriage_return).
     */
    public function cr(): void
    {
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($this->cursor->row, 0);
    }

    /**
     * LF — line feed: advance cursor down one row, scrolling the
     * scroll region if the cursor is at scrollBottom.
     * Handles VT (0x0B) and FF (0x0C) the same way.
     * Vertical motion consumes the phantom cell (emulator index()).
     */
    public function lf(): void
    {
        $this->wrapPending = false;
        if ($this->cursor->row >= $this->scrollBottom) {
            $this->scrollUp(1);
        } else {
            $this->cursor = $this->cursor->at($this->cursor->row + 1, $this->cursor->col);
        }
    }

    /**
     * SU — Scroll Up (CSI S). Scroll the scroll region up $count lines,
     * introducing blank lines at the bottom. Cursor position unchanged.
     */
    public function su(int $count = 1): void
    {
        $this->scrollUp(max(1, $count));
    }

    /**
     * SD — Scroll Down (CSI T). Scroll the scroll region down $count lines,
     * introducing blank lines at the top. Cursor position unchanged.
     */
    public function sd(int $count = 1): void
    {
        $this->scrollDown(max(1, $count));
    }

    /**
     * IL — Insert Line (CSI L). Insert $count blank lines at the cursor row,
     * shifting existing lines down within the scroll region. No-op when the
     * cursor sits outside the scroll region. On success the cursor homes to
     * column 0 on its row — matching the emulator's insertLines() (VT500 §IL
     * "cursor to home position"; charm keeps the phantom armed against the OLD
     * margin, so column-home disarms it — see ScreenHandler::insertLines).
     */
    public function il(int $count = 1): void
    {
        $row = $this->cursor->row;
        if ($row < $this->scrollTop || $row > $this->scrollBottom) {
            return;
        }
        $count = min(max(1, $count), $this->scrollBottom - $row + 1);
        $cols = $this->grid->cols;

        for ($r = $this->scrollBottom; $r >= $row + $count; $r--) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->set($r, $c, $this->grid->get($r - $count, $c));
            }
        }
        for ($r = $row; $r < $row + $count; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->set($r, $c, Cell::empty());
            }
        }
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($row, 0);
    }

    /**
     * DL — Delete Line (CSI M). Delete $count lines at the cursor row,
     * shifting lines below up within the scroll region. No-op when the
     * cursor sits outside the scroll region. Cursor homes to column 0 like
     * {@see il()}. (The emulator additionally pushes deleted rows into its
     * scrollback when the region spans the screen; the renderer has no
     * scrollback, so its visible-grid edit is identical.)
     */
    public function dl(int $count = 1): void
    {
        $row = $this->cursor->row;
        if ($row < $this->scrollTop || $row > $this->scrollBottom) {
            return;
        }
        $count = min(max(1, $count), $this->scrollBottom - $row + 1);
        $cols = $this->grid->cols;

        for ($r = $row; $r <= $this->scrollBottom - $count; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->set($r, $c, $this->grid->get($r + $count, $c));
            }
        }
        for ($r = $this->scrollBottom - $count + 1; $r <= $this->scrollBottom; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->set($r, $c, Cell::empty());
            }
        }
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($row, 0);
    }

    /**
     * ICH — Insert Character (CSI @). Insert $count blank cells at the cursor,
     * shifting the rest of the line right; cells pushed past the right edge
     * are dropped. Cursor position unchanged.
     */
    public function ich(int $count = 1): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;
        $cols = $this->grid->cols;
        $count = min(max(1, $count), $cols - $col);
        if ($count <= 0) {
            return;
        }

        for ($c = $cols - 1; $c >= $col + $count; $c--) {
            $this->grid->set($row, $c, $this->grid->get($row, $c - $count));
        }
        for ($c = $col; $c < $col + $count; $c++) {
            $this->grid->set($row, $c, Cell::empty());
        }
    }

    /**
     * DCH — Delete Character (CSI P). Delete $count cells at the cursor,
     * shifting the rest of the line left and blanking the vacated right edge.
     * Cursor position unchanged.
     */
    public function dch(int $count = 1): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;
        $cols = $this->grid->cols;
        $count = min(max(1, $count), $cols - $col);
        if ($count <= 0) {
            return;
        }

        for ($c = $col; $c < $cols - $count; $c++) {
            $this->grid->set($row, $c, $this->grid->get($row, $c + $count));
        }
        for ($c = $cols - $count; $c < $cols; $c++) {
            $this->grid->set($row, $c, Cell::empty());
        }
    }

    /**
     * REP — Repeat (CSI b). Replay the last printed graphic grapheme $count
     * times. No-op when nothing printable has been emitted yet.
     */
    public function rep(int $count = 1): void
    {
        if ($this->lastPrintable === '') {
            return;
        }
        $grapheme = $this->lastPrintable;
        $count = max(1, $count);
        for ($i = 0; $i < $count; $i++) {
            $this->printable($grapheme);
        }
    }

    /**
     * SCOSC — SCO Save Cursor (CSI s). Remember the current cursor position.
     */
    public function scosc(): void
    {
        $this->savedCursor = $this->cursor;
    }

    /**
     * SCORC — SCO Restore Cursor (CSI u). Restore the cursor saved by SCOSC;
     * no-op when nothing was saved. A position change on the way back
     * disarms the phantom cell (emulator: cursor-handler dispatch clears for
     * every final except 's').
     *
     * Position only — visibility and shape are live state the emulator does
     * not snapshot either (`Cursor\Cursor::restore()` carries savedRow/savedCol
     * alone), so a `CSI s` … `CSI ? 25 l` … `CSI u` keeps the cursor hidden.
     */
    public function scorc(): void
    {
        if ($this->savedCursor !== null) {
            $this->cursor = $this->cursor->at($this->savedCursor->row, $this->savedCursor->col);
        }
        $this->wrapPending = false;
    }

    /**
     * Consume a pending line break: column 0 of the next row, scrolling the
     * region when already at its bottom. Mirrors the emulator's
     * ScreenHandler::lineFeedNext() — the phantom flag is cleared here.
     */
    private function lineFeedWrap(): void
    {
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($this->cursor->row, 0);
        if ($this->cursor->row >= $this->scrollBottom) {
            $this->scrollUp(1);
            return;
        }
        $this->cursor = $this->cursor->at($this->cursor->row + 1, 0);
    }

    private function scrollUp(int $count): void
    {
        $height = $this->scrollBottom - $this->scrollTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->scrollUpOne();
        }
    }

    private function scrollUpOne(): void
    {
        $top = $this->scrollTop;
        $bottom = $this->scrollBottom;
        $cols = $this->grid->cols;

        for ($r = $top; $r < $bottom; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $next = $this->grid->get($r + 1, $c);
                $this->grid->set($r, $c, $next);
            }
        }

        for ($c = 0; $c < $cols; $c++) {
            $this->grid->set($bottom, $c, Cell::empty());
        }
    }

    private function scrollDown(int $count): void
    {
        $height = $this->scrollBottom - $this->scrollTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->scrollDownOne();
        }
    }

    private function scrollDownOne(): void
    {
        $top = $this->scrollTop;
        $bottom = $this->scrollBottom;
        $cols = $this->grid->cols;

        for ($r = $bottom; $r > $top; $r--) {
            for ($c = 0; $c < $cols; $c++) {
                $prev = $this->grid->get($r - 1, $c);
                $this->grid->set($r, $c, $prev);
            }
        }

        for ($c = 0; $c < $cols; $c++) {
            $this->grid->set($top, $c, Cell::empty());
        }
    }
}
